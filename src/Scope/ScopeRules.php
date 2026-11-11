<?php declare(strict_types=1);

namespace Mn\SalesChannelScope\Scope;

use Mn\SalesChannelScope\Category\CategoryTrees;
use Mn\SalesChannelScope\Cms\CmsPages;
use Mn\SalesChannelScope\Rule\ReadRule;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Filter\AndFilter;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Filter\EqualsAnyFilter;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Filter\EqualsFilter;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Filter\Filter;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Filter\NotFilter;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Filter\OrFilter;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Filter\PrefixFilter;

/**
 * Which records of an entity a restricted user may see: only what is
 * connected to their sales channels.
 *
 * Iteration 1: sales channels, orders and their details, customers and their
 * addresses. Iteration 2: products (sold in one of the channels; variants
 * through their parent), their details, product reviews, categories (the
 * trees of the channels, see CategoryTrees), main categories. Hardening:
 * sales channel settings (configuration, SEO URLs, domains) of other channels.
 * Shopping Experience layouts and their parts (see CmsPages).
 */
class ScopeRules implements ReadRule
{
    private readonly ConfigRules $config;

    public function __construct(
        private readonly ?CategoryTrees $categories = null,
        private readonly ?CmsPages $cms = null,
        ?ConfigRules $config = null,
    ) {
        $this->config = $config ?? new ConfigRules();
    }

    /** Entities that belong to a promotion (association "promotion"). */
    public const PROMOTION_CHILDREN = ['promotion_discount', 'promotion_setgroup', 'promotion_individual_code', 'promotion_translation'];

    /** Entities that belong to a landing page (column landing_page_id / association "landingPage"). */
    public const LANDING_PAGE_CHILDREN = ['landing_page_translation'];

    /** Shopping Experience layout parts: entity => path to the layout id. */
    public const CMS_PARTS = [
        'cms_page' => 'id',
        'cms_section' => 'pageId',
        'cms_block' => 'section.pageId',
        'cms_slot' => 'block.section.pageId',
    ];

    /** Entities that belong to an order (column order_id / association "order"). */
    public const ORDER_CHILDREN = [
        'order_line_item',
        'order_delivery',
        'order_transaction',
        'order_address',
        'order_customer',
        'document',
    ];

    /** Entities that belong to a product (column product_id / association "product"). */
    public const PRODUCT_CHILDREN = [
        'product_price',
        'product_media',
        'product_visibility',
        'product_configurator_setting',
        'product_cross_selling',
        'product_download',
        'product_search_keyword',
        'product_translation',
    ];

    /**
     * Records that belong to one sales channel (column sales_channel_id) or,
     * without one, to all of them: configuration values (payment credentials
     * among them), SEO URLs and templates, domains, product comparison
     * exports, files, and Commercial's review summaries (per product and channel).
     */
    public const CHANNEL_SETTINGS = [
        'system_config',
        'seo_url',
        'seo_url_template',
        'sales_channel_domain',
        'product_export',
        'sales_channel_file',
        'product_review_summary',
    ];

    /**
     * What a sales channel offers (column / primary key sales_channel_id):
     * countries, currencies, languages, payment and shipping methods, its
     * translations and its theme.
     */
    public const CHANNEL_MAPPINGS = [
        'sales_channel_country',
        'sales_channel_currency',
        'sales_channel_language',
        'sales_channel_payment_method',
        'sales_channel_shipping_method',
        'sales_channel_translation',
        'theme_sales_channel',
    ];

    /**
     * @param list<string> $salesChannelIds
     */
    public function readFilter(string $entity, array $salesChannelIds): ?Filter
    {
        return match (true) {
            $entity === 'sales_channel' => new EqualsAnyFilter('id', $salesChannelIds),
            $entity === 'order' => new EqualsAnyFilter('salesChannelId', $salesChannelIds),
            \in_array($entity, self::ORDER_CHILDREN, true) => new EqualsAnyFilter('order.salesChannelId', $salesChannelIds),
            $entity === 'customer' => $this->customer('', $salesChannelIds),
            $entity === 'customer_address', $entity === 'customer_recovery' => $this->customer('customer.', $salesChannelIds),
            $entity === 'product' => $this->product('', $salesChannelIds),
            \in_array($entity, self::PRODUCT_CHILDREN, true) => $this->product('product.', $salesChannelIds),
            $entity === 'product_review' => new EqualsAnyFilter('salesChannelId', $salesChannelIds),
            $entity === 'main_category' => new EqualsAnyFilter('salesChannelId', $salesChannelIds),
            $entity === 'category' => $this->categories?->readFilter($salesChannelIds),
            $entity === 'system_config' => $this->systemConfig($salesChannelIds),
            $entity === 'landing_page' => new EqualsAnyFilter('salesChannels.id', $salesChannelIds),
            $entity === 'promotion' => new EqualsAnyFilter('salesChannels.salesChannelId', $salesChannelIds),
            $entity === 'promotion_sales_channel' => new EqualsAnyFilter('salesChannelId', $salesChannelIds),
            \in_array($entity, self::PROMOTION_CHILDREN, true) => new EqualsAnyFilter('promotion.salesChannels.salesChannelId', $salesChannelIds),
            $entity === 'promotion_discount_prices' => new EqualsAnyFilter('promotionDiscount.promotion.salesChannels.salesChannelId', $salesChannelIds),
            $entity === 'product_review_summary_translation' => new EqualsAnyFilter('productReviewSummary.salesChannelId', $salesChannelIds),
            \in_array($entity, self::CHANNEL_MAPPINGS, true) => new EqualsAnyFilter('salesChannelId', $salesChannelIds),
            $entity === 'sales_channel_analytics' => new EqualsAnyFilter('salesChannel.id', $salesChannelIds),
            \in_array($entity, self::LANDING_PAGE_CHILDREN, true) => new EqualsAnyFilter('landingPage.salesChannels.id', $salesChannelIds),
            isset(self::CMS_PARTS[$entity]) && $this->cms !== null => new EqualsAnyFilter(
                self::CMS_PARTS[$entity],
                $this->cms->visibleIds($salesChannelIds) ?: ['00000000000000000000000000000000'],
            ),
            \in_array($entity, self::CHANNEL_SETTINGS, true) => new OrFilter([
                new EqualsAnyFilter('salesChannelId', $salesChannelIds),
                new EqualsFilter('salesChannelId', null),
            ]),
            default => null,
        };
    }

    /**
     * Visible: Shopware's own settings of the user's channels and of all
     * channels, without hidden domains (see ConfigRules).
     *
     * @param list<string> $salesChannelIds
     */
    private function systemConfig(array $salesChannelIds): Filter
    {
        $prefixes = static fn (array $domains) => array_map(static fn (string $domain) => new PrefixFilter('configurationKey', $domain . '.'), $domains);

        return new AndFilter([
            new OrFilter([
                new EqualsAnyFilter('salesChannelId', $salesChannelIds),
                new EqualsFilter('salesChannelId', null),
            ]),
            new OrFilter($prefixes($this->config->readableDomains())),
            new NotFilter(NotFilter::CONNECTION_OR, $prefixes($this->config->hiddenDomains())),
        ]);
    }

    /**
     * Visible: sold in one of the channels. Variants usually have no
     * visibilities of their own and inherit their parent's, so the parent's
     * count too (independent of the context's inheritance setting).
     *
     * @param list<string> $salesChannelIds
     */
    private function product(string $prefix, array $salesChannelIds): Filter
    {
        return new OrFilter([
            new EqualsAnyFilter($prefix . 'visibilities.salesChannelId', $salesChannelIds),
            new EqualsAnyFilter($prefix . 'parent.visibilities.salesChannelId', $salesChannelIds),
        ]);
    }

    /**
     * Visible: registered in, bound to, or ordered in one of the channels.
     *
     * @param list<string> $salesChannelIds
     */
    private function customer(string $prefix, array $salesChannelIds): Filter
    {
        return new OrFilter([
            new EqualsAnyFilter($prefix . 'salesChannelId', $salesChannelIds),
            new EqualsAnyFilter($prefix . 'boundSalesChannelId', $salesChannelIds),
            new EqualsAnyFilter($prefix . 'orderCustomers.order.salesChannelId', $salesChannelIds),
        ]);
    }
}
