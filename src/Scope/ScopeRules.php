<?php declare(strict_types=1);

namespace Mn\SalesChannelScope\Scope;

use Mn\SalesChannelScope\Category\CategoryTrees;
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
 */
class ScopeRules
{
    public function __construct(private readonly ?CategoryTrees $categories = null)
    {
    }

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
    ];

    /**
     * Settings that belong to one sales channel (column sales_channel_id) or,
     * without one, to all of them: configuration values (payment credentials
     * among them), SEO URLs and domains.
     */
    public const CHANNEL_SETTINGS = [
        'system_config',
        'seo_url',
        'seo_url_template',
        'sales_channel_domain',
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
            $entity === 'customer_address' => $this->customer('customer.', $salesChannelIds),
            $entity === 'product' => $this->product('', $salesChannelIds),
            \in_array($entity, self::PRODUCT_CHILDREN, true) => $this->product('product.', $salesChannelIds),
            $entity === 'product_review' => new EqualsAnyFilter('salesChannelId', $salesChannelIds),
            $entity === 'main_category' => new EqualsAnyFilter('salesChannelId', $salesChannelIds),
            $entity === 'category' => $this->categories?->readFilter($salesChannelIds),
            $entity === 'system_config' => $this->systemConfig($salesChannelIds),
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
        $hidden = array_map(static fn (string $domain) => new PrefixFilter('configurationKey', $domain . '.'), ConfigRules::HIDDEN_DOMAINS);

        return new AndFilter([
            new OrFilter([
                new EqualsAnyFilter('salesChannelId', $salesChannelIds),
                new EqualsFilter('salesChannelId', null),
            ]),
            new PrefixFilter('configurationKey', 'core.'),
            new NotFilter(NotFilter::CONNECTION_OR, $hidden),
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
