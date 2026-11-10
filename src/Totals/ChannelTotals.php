<?php declare(strict_types=1);

namespace Mn\SalesChannelScope\Totals;

/**
 * Totals Shopware stores across all sales channels, counted for some
 * channels only. Same rules as Shopware: completed orders for customers,
 * approved reviews, sales of orders that are not cancelled.
 */
interface ChannelTotals
{
    /**
     * @param list<string> $customerIds hex
     * @param list<string> $salesChannelIds hex
     *
     * @return array<string, array{orderCount: int, orderTotalAmount: float, lastOrderDate: ?\DateTimeImmutable, reviewCount: int}> by customer id
     */
    public function customers(array $customerIds, array $salesChannelIds): array;

    /**
     * Average approved review points of a product and its variants, by the
     * product's root id (as Shopware stores rating_average on the parent).
     *
     * @param list<string> $rootIds hex
     * @param list<string> $salesChannelIds hex
     *
     * @return array<string, float> by root id; missing = no reviews
     */
    public function ratings(array $rootIds, array $salesChannelIds): array;

    /**
     * Sold quantity of each product in orders that are not cancelled.
     *
     * @param list<string> $productIds hex
     * @param list<string> $salesChannelIds hex
     *
     * @return array<string, int> by product id; missing = 0
     */
    public function sales(array $productIds, array $salesChannelIds): array;
}
