<?php declare(strict_types=1);

namespace Mn\SalesChannelScope\Subscriber;

use Mn\SalesChannelScope\Category\CategoryTrees;
use Mn\SalesChannelScope\Entity\AclRoleSalesChannelDefinition;
use Mn\SalesChannelScope\Exception\ChannelScopeException;
use Mn\SalesChannelScope\Scope\ChannelScope;
use Mn\SalesChannelScope\Scope\ConfigRules;
use Mn\SalesChannelScope\Scope\ScopeRules;
use Mn\SalesChannelScope\Write\ScopeLookup;
use Shopware\Core\Defaults;
use Shopware\Core\Framework\DataAbstractionLayer\Write\Command\DeleteCommand;
use Shopware\Core\Framework\DataAbstractionLayer\Write\Command\InsertCommand;
use Shopware\Core\Framework\DataAbstractionLayer\Write\Command\WriteCommand;
use Shopware\Core\Framework\DataAbstractionLayer\Write\Validation\PreWriteValidationEvent;
use Shopware\Core\Framework\Uuid\Uuid;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;

/**
 * Refuses writes of a restricted admin user that touch records outside
 * their sales channels, before and after the change:
 *
 * - orders and their details: the order's channel must be theirs, and an
 *   order can't move to another channel;
 * - customers and their addresses: registered in or bound to one of their
 *   channels (customers who only ordered there are read-only);
 * - sales channels: only their own can be updated, none created or deleted;
 * - the role-to-channel mapping: only unrestricted users change it;
 * - products and their details: only when the product is sold only in their
 *   channels (shared products are read-only); a new product needs a
 *   visibility in one of their channels, a new variant a parent they own;
 * - product visibilities and main categories: only for their own channels
 *   and products they see, also on shared products;
 * - product reviews: only of their channels;
 * - categories: only those that belong to their channels only (shared trees
 *   and the ancestors of their entry categories are read-only); new categories
 *   below such a category; no deleting or moving of a subtree that holds
 *   another channel's entry category; products can only be assigned to
 *   categories the user sees;
 * - sales channel entry categories: only categories the user may edit;
 * - sales channel settings (configuration, SEO URLs and templates, domains):
 *   only those of their channels, none that apply to all channels;
 *   configuration only on the pages of the settings preset (ConfigRules);
 * - product sortings: they apply to all channels;
 * - what a sales channel offers (countries, currencies, languages, payment
 *   and shipping methods, translations, theme assignment) and its analytics:
 *   only for their channels;
 * - themes: only those used by their channels only;
 * - rules: only new ones (with their conditions); payment and shipping
 *   methods and Commercial's dynamic access rules not at all.
 */
class WriteGuardSubscriber implements EventSubscriberInterface
{
    /** Product tables whose records belong to one product (column product_id). */
    public const PRODUCT_WRITE_CHILDREN = [
        'product_price',
        'product_media',
        'product_configurator_setting',
        'product_cross_selling',
        'product_download',
        'product_search_keyword',
        'product_category',
        'product_option',
        'product_property',
        'product_tag',
        'product_custom_field_set',
        'product_translation',
    ];

    /** Records that apply to all sales channels, which Shopware's promotion editor role can change. */
    public const GLOBAL_RECORDS = [
        'payment_method',
        'payment_method_translation',
        'shipping_method',
        'shipping_method_translation',
        'swag_dynamic_access_category_rule',
        'swag_dynamic_access_product_rule',
        'swag_dynamic_access_landing_page_rule',
    ];

    /** Landing page tables whose records belong to one landing page (column landing_page_id). */
    public const LANDING_PAGE_WRITE_CHILDREN = ['landing_page_translation', 'landing_page_sales_channel', 'landing_page_tag'];

    /** Category tables whose records belong to one category (column category_id). */
    public const CATEGORY_WRITE_CHILDREN = ['category_translation', 'category_tag'];

    private const ENTRY_POINT_COLUMNS = ['navigation_category_id', 'footer_category_id', 'service_category_id'];

    /** Payload columns of an update that only touches the record (nested writes of its children). */
    private const TOUCH_COLUMNS = ['updated_at', 'updated_by_id'];

    private readonly ConfigRules $config;

    public function __construct(
        private readonly ChannelScope $scope,
        private readonly ScopeLookup $lookup,
        private readonly ?CategoryTrees $categories = null,
        ?ConfigRules $config = null,
    ) {
        $this->config = $config ?? new ConfigRules();
    }

    public static function getSubscribedEvents(): array
    {
        return [PreWriteValidationEvent::class => 'onPreWrite'];
    }

    public function onPreWrite(PreWriteValidationEvent $event): void
    {
        $ids = $this->scope->salesChannelIds($event->getContext());
        if ($ids === null) {
            return;
        }

        $allowed = array_flip($ids);
        $commands = $event->getCommands();
        [$newOrders, $newCustomers, $newProducts] = $this->inserted($commands);

        $newCategories = [];
        $newLandingPages = [];
        $newRules = [];
        foreach ($commands as $command) {
            if ($command instanceof InsertCommand && $command->getEntityName() === 'rule' && \is_string($id = $command->getPrimaryKey()['id'] ?? null)) {
                $newRules[Uuid::fromBytesToHex($id)] = true;
            }
            if ($command instanceof InsertCommand && $command->getEntityName() === 'landing_page' && \is_string($id = $command->getPrimaryKey()['id'] ?? null)) {
                $newLandingPages[Uuid::fromBytesToHex($id)] = true;
            }
            if ($command instanceof InsertCommand && $command->getEntityName() === 'category' && \is_string($id = $command->getPrimaryKey()['id'] ?? null)) {
                $newCategories[Uuid::fromBytesToHex($id)] = true;
            }
        }

        foreach ($commands as $command) {
            $reason = $this->denyGlobal($command, $newRules)
                ?? $this->deny($command, $allowed, $newOrders, $newCustomers, $newLandingPages)
                ?? $this->denyProduct($command, $allowed, $newProducts)
                ?? $this->denyCategory($command, $ids, $newCategories);
            if ($reason !== null) {
                $event->getExceptions()->add(ChannelScopeException::writeDenied($command->getEntityName(), $reason));
            }
        }
    }

    /**
     * Rules, payment and shipping methods apply to all sales channels. Limited
     * users may create rules (with their conditions), for example for the
     * conditions of a promotion, but not change existing ones.
     *
     * @param array<string, true> $newRules
     */
    private function denyGlobal(WriteCommand $command, array $newRules): ?string
    {
        $entity = $command->getEntityName();
        if (\in_array($entity, self::GLOBAL_RECORDS, true)) {
            return 'it applies to all sales channels';
        }
        if ($entity === 'rule' && !$command instanceof InsertCommand) {
            return 'rules apply to all sales channels; you can create new ones';
        }
        if ($entity === 'rule_condition') {
            $rule = $this->hex($command->getPayload()['rule_id'] ?? null);
            if (!$command instanceof InsertCommand || $rule === null || !isset($newRules[$rule])) {
                return 'rules apply to all sales channels; you can create new ones';
            }
        }

        return null;
    }

    /**
     * Orders and customers inserted in the same write (their children are checked against them).
     *
     * @param list<WriteCommand> $commands
     *
     * @return array{array<string, ?string>, array<string, array{salesChannelId: string, boundSalesChannelId: ?string}>, array<string, list<string>>}
     */
    private function inserted(array $commands): array
    {
        $orders = [];
        $customers = [];
        $products = [];
        foreach ($commands as $command) {
            if ($command instanceof InsertCommand && $command->getEntityName() === 'product' && \is_string($id = $command->getPrimaryKey()['id'] ?? null)) {
                $products[Uuid::fromBytesToHex($id)] ??= [];
            }
        }
        foreach ($commands as $command) {
            $productId = $this->hex($command->getPayload()['product_id'] ?? null);
            if ($command instanceof InsertCommand && $command->getEntityName() === 'product_visibility' && $productId !== null && isset($products[$productId])) {
                $products[$productId][] = (string) $this->hex($command->getPayload()['sales_channel_id'] ?? null);
            }
        }
        foreach ($commands as $command) {
            if (!$command instanceof InsertCommand) {
                continue;
            }
            $id = $command->getPrimaryKey()['id'] ?? null;
            if (!\is_string($id)) {
                continue;
            }
            $payload = $command->getPayload();
            if ($command->getEntityName() === 'order') {
                $orders[Uuid::fromBytesToHex($id)] = $this->hex($payload['sales_channel_id'] ?? null);
            } elseif ($command->getEntityName() === 'customer') {
                $customers[Uuid::fromBytesToHex($id)] = [
                    'salesChannelId' => (string) $this->hex($payload['sales_channel_id'] ?? null),
                    'boundSalesChannelId' => $this->hex($payload['bound_sales_channel_id'] ?? null),
                ];
            }
        }

        return [$orders, $customers, $products];
    }

    /**
     * @param list<string> $channels
     * @param array<string, true> $newCategories
     */
    private function denyCategory(WriteCommand $command, array $channels, array $newCategories): ?string
    {
        if ($this->categories === null) {
            return null;
        }

        $entity = $command->getEntityName();
        $primaryKey = $command->getPrimaryKey();
        $payload = $command->getPayload();
        $editable = fn (?string $id): bool => $id !== null && (isset($newCategories[$id]) || $this->categories->isEditable($id, $channels));

        if ($entity === 'category') {
            $id = $this->hex($primaryKey['id'] ?? null);
            $parent = \array_key_exists('parent_id', $payload) ? $this->hex($payload['parent_id']) : false;
            if ($command instanceof InsertCommand) {
                if ($parent === false || $parent === null) {
                    return 'only unrestricted users create root categories';
                }

                return $editable($parent) ? null : 'new categories can only be added below categories that belong only to your sales channels';
            }
            if (!$editable($id)) {
                return 'the category is also used by other sales channels';
            }
            if (($command instanceof DeleteCommand || $parent !== false) && $this->categories->hasForeignEntriesBelow((string) $id, $channels)) {
                return 'another sales channel\'s category tree starts below this category';
            }
            if ($parent !== false && !$editable($parent)) {
                return 'categories can only be moved below categories that belong only to your sales channels';
            }

            return null;
        }

        if (\in_array($entity, self::CATEGORY_WRITE_CHILDREN, true)) {
            $category = $this->hex($payload['category_id'] ?? $primaryKey['category_id'] ?? null);

            return $editable($category) ? null : 'its category is also used by other sales channels';
        }

        if ($entity === 'product_category' || $entity === 'main_category') {
            $category = $this->hex($payload['category_id'] ?? $primaryKey['category_id'] ?? null);
            if ($category !== null && !isset($newCategories[$category]) && !$this->categories->isVisible($category, $channels)) {
                return 'products can only be assigned to categories of your sales channels';
            }
        }

        return null;
    }

    /**
     * @param array<string, int> $allowed
     * @param array<string, ?string> $newOrders
     * @param array<string, array{salesChannelId: string, boundSalesChannelId: ?string}> $newCustomers
     * @param array<string, true> $newLandingPages
     */
    private function deny(WriteCommand $command, array $allowed, array $newOrders, array $newCustomers, array $newLandingPages = []): ?string
    {
        $entity = $command->getEntityName();
        $primaryKey = $command->getPrimaryKey();
        $payload = $command->getPayload();
        $insert = $command instanceof InsertCommand;

        if ($entity === AclRoleSalesChannelDefinition::ENTITY_NAME) {
            return 'only unrestricted users change the sales channels of roles';
        }

        if ($entity === 'sales_channel') {
            if ($insert || $command instanceof DeleteCommand) {
                return 'only unrestricted users create or delete sales channels';
            }

            if (!isset($allowed[$this->hex($primaryKey['id'] ?? null) ?? ''])) {
                return 'the sales channel is not one of yours';
            }
            foreach (self::ENTRY_POINT_COLUMNS as $column) {
                $category = $this->hex($payload[$column] ?? null);
                if ($category !== null && $this->categories !== null && !$this->categories->isEditable($category, array_keys($allowed))) {
                    return 'entry categories must be categories that belong only to your sales channels';
                }
            }

            return null;
        }

        if ($entity === 'order') {
            if (!$insert) {
                $current = $this->lookup->orderChannel((string) ($primaryKey['id'] ?? ''), $primaryKey['version_id'] ?? $this->liveVersion());
                if ($current === null || !isset($allowed[$current])) {
                    return 'the order belongs to another sales channel';
                }
            }
            if (\array_key_exists('sales_channel_id', $payload) && !isset($allowed[$this->hex($payload['sales_channel_id']) ?? ''])) {
                return 'orders can only be placed in or moved to your sales channels';
            }

            return null;
        }

        if (\in_array($entity, ScopeRules::ORDER_CHILDREN, true)) {
            if (!$insert) {
                $current = $this->lookup->orderChannelOf($entity, $primaryKey);
                if ($current === null || !isset($allowed[$current])) {
                    return 'its order belongs to another sales channel';
                }
            }
            if (\array_key_exists('order_id', $payload)) {
                $orderId = $this->hex($payload['order_id']) ?? '';
                $channel = \array_key_exists($orderId, $newOrders)
                    ? $newOrders[$orderId]
                    : $this->lookup->orderChannel((string) $payload['order_id'], $payload['order_version_id'] ?? $this->liveVersion());
                if ($channel === null || !isset($allowed[$channel])) {
                    return 'its order belongs to another sales channel';
                }
            }

            return null;
        }

        if ($entity === 'customer') {
            if (!$insert) {
                $current = $this->lookup->customerChannels((string) ($primaryKey['id'] ?? ''));
                if (!$this->ownsCustomer($current, $allowed)) {
                    return 'the customer is registered in another sales channel';
                }
            }
            if (\array_key_exists('sales_channel_id', $payload) && !isset($allowed[$this->hex($payload['sales_channel_id']) ?? ''])) {
                return 'customers can only be registered in your sales channels';
            }
            $bound = $payload['bound_sales_channel_id'] ?? null;
            if ($bound !== null && !isset($allowed[$this->hex($bound) ?? ''])) {
                return 'customers can only be bound to your sales channels';
            }

            return null;
        }

        if ($entity === 'customer_address') {
            if (!$insert && !$this->ownsCustomer($this->lookup->customerChannelsOfAddress($primaryKey), $allowed)) {
                return 'its customer is registered in another sales channel';
            }
            if (\array_key_exists('customer_id', $payload)) {
                $customerId = $this->hex($payload['customer_id']) ?? '';
                $customer = $newCustomers[$customerId] ?? $this->lookup->customerChannels((string) $payload['customer_id']);
                if (!$this->ownsCustomer($customer, $allowed)) {
                    return 'its customer is registered in another sales channel';
                }
            }

            return null;
        }

        if ($entity === 'landing_page' || \in_array($entity, self::LANDING_PAGE_WRITE_CHILDREN, true)) {
            $column = $entity === 'landing_page' ? 'id' : 'landing_page_id';
            $id = $primaryKey[$column] ?? $payload[$column] ?? null;
            if ($entity === 'landing_page' && $insert) {
                return null;
            }
            $channels = \is_string($id) ? $this->lookup->landingPageChannels($id, $primaryKey['version_id'] ?? $primaryKey['landing_page_version_id'] ?? $payload['landing_page_version_id'] ?? $this->liveVersion()) : null;
            if ($channels === null && $entity !== 'landing_page_sales_channel') {
                // a landing page inserted in this write: its channels are checked below
                return \is_string($id) && isset($newLandingPages[Uuid::fromBytesToHex($id)]) ? null : 'the landing page belongs to other sales channels';
            }
            if ($channels !== null && ($channels === [] || array_diff($channels, array_keys($allowed)) !== [])) {
                return 'the landing page is also shown in other sales channels';
            }
            if ($entity === 'landing_page_sales_channel' && !isset($allowed[$this->hex($primaryKey['sales_channel_id'] ?? null) ?? ''])) {
                return 'landing pages can only be shown in your sales channels';
            }

            return null;
        }

        return null;
    }

    /**
     * @param array<string, int> $allowed
     * @param array<string, list<string>> $newProducts product id => sales channels of the visibilities inserted with it
     */
    private function denyProduct(WriteCommand $command, array $allowed, array $newProducts): ?string
    {
        $entity = $command->getEntityName();
        $primaryKey = $command->getPrimaryKey();
        $payload = $command->getPayload();
        $insert = $command instanceof InsertCommand;

        if ($entity === 'product') {
            $id = $this->hex($primaryKey['id'] ?? null) ?? '';
            $parentId = $payload['parent_id'] ?? null;
            if ($insert) {
                if (\is_string($parentId)) {
                    return $this->ownsProduct($parentId, $allowed, $newProducts) ? null : 'variants can only be added to products that are sold only in your sales channels';
                }
                foreach ($newProducts[$id] ?? [] as $channel) {
                    if (isset($allowed[$channel])) {
                        return null;
                    }
                }

                return 'a new product needs a visibility in one of your sales channels';
            }

            $touchOnly = !$command instanceof DeleteCommand && array_diff(array_keys($payload), self::TOUCH_COLUMNS) === [];
            $channels = $this->lookup->productChannels((string) ($primaryKey['id'] ?? ''));
            if ($touchOnly ? !$this->sees($channels, $allowed) : !$this->owns($channels, $allowed)) {
                return 'the product is also sold in other sales channels';
            }
            if (\is_string($parentId) && !$this->ownsProduct($parentId, $allowed, $newProducts)) {
                return 'the new parent product is also sold in other sales channels';
            }

            return null;
        }

        if (\in_array($entity, self::PRODUCT_WRITE_CHILDREN, true)) {
            $productId = \is_string($payload['product_id'] ?? null) ? $payload['product_id'] : $this->lookup->productIdOf($entity, $primaryKey);
            if ($productId === null || !$this->ownsProduct($productId, $allowed, $newProducts)) {
                return 'its product is also sold in other sales channels';
            }

            return null;
        }

        if ($entity === 'product_visibility' || $entity === 'main_category') {
            $stored = $insert ? null : $this->lookup->productChannelRecord($entity, $primaryKey);
            if (!$insert && ($stored === null || !isset($allowed[$stored['salesChannelId']]))) {
                return 'it belongs to another sales channel';
            }
            if (\array_key_exists('sales_channel_id', $payload) && !isset($allowed[$this->hex($payload['sales_channel_id']) ?? ''])) {
                return 'products can only be added to your sales channels';
            }
            $productId = \is_string($payload['product_id'] ?? null) ? $payload['product_id'] : ($stored['productId'] ?? null);
            if ($productId === null) {
                return 'its product is unknown';
            }
            // Only products the user already sees (or creates now): hidden products stay hidden.
            if (!isset($newProducts[Uuid::fromBytesToHex($productId)]) && !$this->sees($this->lookup->productChannels($productId), $allowed)) {
                return 'its product is not sold in your sales channels';
            }

            return null;
        }

        if ($entity === 'product_review') {
            if (!$insert) {
                $current = $this->lookup->reviewChannel($primaryKey);
                if ($current === null || !isset($allowed[$current])) {
                    return 'the review belongs to another sales channel';
                }
            }
            if (\array_key_exists('sales_channel_id', $payload) && !isset($allowed[$this->hex($payload['sales_channel_id']) ?? ''])) {
                return 'reviews can only belong to your sales channels';
            }

            return null;
        }

        if (\in_array($entity, ScopeRules::CHANNEL_MAPPINGS, true)) {
            $channel = $this->hex($primaryKey['sales_channel_id'] ?? $payload['sales_channel_id'] ?? null);
            if (!isset($allowed[$channel ?? ''])) {
                return 'the sales channel is not one of yours';
            }

            return null;
        }

        if ($entity === 'sales_channel_analytics' && !$insert) {
            $channels = $this->lookup->analyticsChannels((string) ($primaryKey['id'] ?? ''));
            if ($channels === null || $channels === [] || array_diff($channels, array_keys($allowed)) !== []) {
                return 'the analytics settings belong to another sales channel';
            }

            return null;
        }

        if ($entity === 'theme') {
            if ($insert) {
                return 'only unrestricted users install themes';
            }
            $channels = $this->lookup->themeChannels((string) ($primaryKey['id'] ?? ''));
            if ($channels === [] || array_diff($channels, array_keys($allowed)) !== []) {
                return 'the theme is also used by other sales channels';
            }

            return null;
        }

        if ($entity === 'product_review_summary_translation') {
            $current = $this->lookup->settingChannel('product_review_summary', ['id' => (string) ($primaryKey['product_review_summary_id'] ?? '')]);
            if ($current === null && $insert) {
                return null; // a summary inserted in this write: checked as product_review_summary
            }
            if ($current === null || !isset($allowed[$current])) {
                return 'the review summary belongs to another sales channel';
            }

            return null;
        }

        if ($entity === 'product_sorting') {
            return 'product sortings apply to all sales channels';
        }

        if ($entity === 'system_config') {
            $key = \array_key_exists('configuration_key', $payload)
                ? (string) $payload['configuration_key']
                : ($insert ? '' : (string) $this->lookup->configKey($primaryKey));
            if (!$this->config->isEditable($key)) {
                return 'this setting can only be changed by unrestricted users';
            }
        }

        if (\in_array($entity, ScopeRules::CHANNEL_SETTINGS, true)) {
            if (!$insert) {
                $current = $this->lookup->settingChannel($entity, $primaryKey);
                if ($current === null || !isset($allowed[$current])) {
                    return $current === '' ? 'settings for all sales channels can only be changed by unrestricted users' : 'the setting belongs to another sales channel';
                }
            }
            if (($insert || \array_key_exists('sales_channel_id', $payload)) && !isset($allowed[$this->hex($payload['sales_channel_id'] ?? null) ?? ''])) {
                return 'settings can only be stored for your sales channels';
            }

            return null;
        }

        return null;
    }

    /**
     * @param array<string, int> $allowed
     * @param array<string, list<string>> $newProducts
     */
    private function ownsProduct(string $productId, array $allowed, array $newProducts): bool
    {
        if (isset($newProducts[Uuid::fromBytesToHex($productId)])) {
            return true;
        }

        return $this->owns($this->lookup->productChannels($productId), $allowed);
    }

    /**
     * Every channel is the user's (and there is at least one).
     *
     * @param list<string>|null $channels
     * @param array<string, int> $allowed
     */
    private function owns(?array $channels, array $allowed): bool
    {
        if ($channels === null || $channels === []) {
            return false;
        }
        foreach ($channels as $channel) {
            if (!isset($allowed[$channel])) {
                return false;
            }
        }

        return true;
    }

    /**
     * @param list<string>|null $channels
     * @param array<string, int> $allowed
     */
    private function sees(?array $channels, array $allowed): bool
    {
        foreach ($channels ?? [] as $channel) {
            if (isset($allowed[$channel])) {
                return true;
            }
        }

        return false;
    }

    /**
     * @param array{salesChannelId: string, boundSalesChannelId: ?string}|null $customer
     * @param array<string, int> $allowed
     */
    private function ownsCustomer(?array $customer, array $allowed): bool
    {
        if ($customer === null) {
            return false;
        }

        return isset($allowed[$customer['salesChannelId']])
            || ($customer['boundSalesChannelId'] !== null && isset($allowed[$customer['boundSalesChannelId']]));
    }

    private function hex(mixed $bytes): ?string
    {
        return \is_string($bytes) && \strlen($bytes) === 16 ? Uuid::fromBytesToHex($bytes) : null;
    }

    private function liveVersion(): string
    {
        return Uuid::fromHexToBytes(Defaults::LIVE_VERSION);
    }
}
