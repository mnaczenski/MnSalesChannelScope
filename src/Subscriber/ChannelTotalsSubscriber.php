<?php declare(strict_types=1);

namespace Mn\SalesChannelScope\Subscriber;

use Mn\SalesChannelScope\Scope\ChannelScope;
use Mn\SalesChannelScope\Totals\ChannelTotals;
use Shopware\Core\Checkout\Customer\CustomerEntity;
use Shopware\Core\Content\Product\ProductEntity;
use Shopware\Core\Framework\DataAbstractionLayer\Entity;
use Shopware\Core\Framework\DataAbstractionLayer\Event\EntityLoadedEvent;
use Shopware\Core\Framework\DataAbstractionLayer\PartialEntity;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;

/**
 * Shopware stores some totals across all sales channels on the customer
 * (orderCount, orderTotalAmount, lastOrderDate, reviewCount) and the product
 * (ratingAverage, sales). For a limited user they are replaced, when loaded,
 * by the same totals counted only in their sales channels, so a customer who
 * also buys elsewhere doesn't reveal that turnover.
 *
 * Filtering or sorting by these fields still uses the stored totals.
 */
class ChannelTotalsSubscriber implements EventSubscriberInterface
{
    private const CUSTOMER_FIELDS = ['orderCount', 'orderTotalAmount', 'lastOrderDate', 'reviewCount'];
    private const PRODUCT_FIELDS = ['ratingAverage', 'sales'];

    public function __construct(
        private readonly ChannelScope $scope,
        private readonly ChannelTotals $totals,
    ) {
    }

    public static function getSubscribedEvents(): array
    {
        return [
            'customer.loaded' => 'onCustomers',
            'customer.partial_loaded' => 'onCustomers',
            'product.loaded' => 'onProducts',
            'product.partial_loaded' => 'onProducts',
        ];
    }

    /**
     * @param EntityLoadedEvent<covariant Entity> $event
     */
    public function onCustomers(EntityLoadedEvent $event): void
    {
        $channels = $this->scope->salesChannelIds($event->getContext());
        if ($channels === null) {
            return;
        }

        $customers = array_filter($event->getEntities(), fn (Entity $entity) => $this->hasAny($entity, self::CUSTOMER_FIELDS));
        if ($customers === []) {
            return;
        }

        $totals = $this->totals->customers(array_values(array_map(static fn (Entity $entity) => $entity->getUniqueIdentifier(), $customers)), $channels);
        foreach ($customers as $customer) {
            $values = $totals[strtolower($customer->getUniqueIdentifier())] ?? null;
            if ($values !== null) {
                $this->apply($customer, $values, self::CUSTOMER_FIELDS);
            }
        }
    }

    /**
     * @param EntityLoadedEvent<covariant Entity> $event
     */
    public function onProducts(EntityLoadedEvent $event): void
    {
        $channels = $this->scope->salesChannelIds($event->getContext());
        if ($channels === null) {
            return;
        }

        $products = array_filter($event->getEntities(), fn (Entity $entity) => $this->hasAny($entity, self::PRODUCT_FIELDS));
        if ($products === []) {
            return;
        }

        $ids = [];
        $roots = [];
        foreach ($products as $product) {
            $id = strtolower($product->getUniqueIdentifier());
            $ids[] = $id;
            $roots[$id] = strtolower((string) ($this->value($product, 'parentId') ?? $id));
        }
        $ratings = $this->totals->ratings(array_values(array_unique($roots)), $channels);
        $sales = $this->totals->sales($ids, $channels);

        foreach ($products as $product) {
            $id = strtolower($product->getUniqueIdentifier());
            $values = ['sales' => $sales[$id] ?? 0];
            // Variants usually inherit the rating (stored on the parent): keep theirs empty.
            if ($this->value($product, 'parentId') === null || $this->value($product, 'ratingAverage') !== null) {
                $values['ratingAverage'] = $ratings[$roots[$id]] ?? null;
            }
            $this->apply($product, $values, self::PRODUCT_FIELDS);
        }
    }

    /**
     * @param list<string> $fields
     */
    private function hasAny(Entity $entity, array $fields): bool
    {
        if ($entity instanceof CustomerEntity || $entity instanceof ProductEntity) {
            return true;
        }
        if ($entity instanceof PartialEntity) {
            foreach ($fields as $field) {
                if ($entity->has($field)) {
                    return true;
                }
            }
        }

        return false;
    }

    /**
     * @param array<string, mixed> $values
     * @param list<string> $fields
     */
    private function apply(Entity $entity, array $values, array $fields): void
    {
        foreach ($fields as $field) {
            if (!\array_key_exists($field, $values)) {
                continue;
            }
            if ($entity instanceof PartialEntity && !$entity->has($field)) {
                continue;
            }
            $entity->assign([$field => $values[$field]]);
        }
    }

    private function value(Entity $entity, string $field): mixed
    {
        if ($entity instanceof PartialEntity) {
            return $entity->has($field) ? $entity->get($field) : null;
        }

        return $entity->get($field);
    }
}
