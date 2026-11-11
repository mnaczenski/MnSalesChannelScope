<?php declare(strict_types=1);

namespace Mn\SalesChannelScope\Promotion;

/**
 * Stored facts about promotions. Ids are hex.
 */
interface PromotionSource
{
    /**
     * Sales channels a promotion is assigned to, null when it doesn't exist.
     *
     * @return list<string>|null
     */
    public function channels(string $promotionId): ?array;

    /**
     * The promotion a stored record belongs to: promotion_discount,
     * promotion_setgroup, promotion_individual_code, promotion_sales_channel
     * and promotion_discount_prices (through its discount).
     */
    public function promotionOf(string $entity, string $id): ?string;

    /**
     * Sales channel of a stored promotion_sales_channel record.
     */
    public function assignmentChannel(string $id): ?string;
}
