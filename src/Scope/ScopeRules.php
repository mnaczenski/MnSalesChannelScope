<?php declare(strict_types=1);

namespace Mn\SalesChannelScope\Scope;

use Shopware\Core\Framework\DataAbstractionLayer\Search\Filter\EqualsAnyFilter;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Filter\Filter;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Filter\OrFilter;

/**
 * Which records of an entity a restricted user may see (iteration 1:
 * sales channels, orders and their details, customers and their addresses).
 */
class ScopeRules
{
    /** Entities that belong to an order (column order_id / association "order"). */
    public const ORDER_CHILDREN = [
        'order_line_item',
        'order_delivery',
        'order_transaction',
        'order_address',
        'order_customer',
        'document',
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
            default => null,
        };
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
