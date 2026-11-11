<?php declare(strict_types=1);

namespace Mn\SalesChannelScope\Tests;

use Mn\SalesChannelScope\Promotion\PromotionSource;

/**
 * PROMO_DE (DE only) with DISCOUNT_DE, PROMO_SHARED (DE and EN) with
 * DISCOUNT_SHARED, PROMO_EN (EN only); ASSIGNMENT_* link them to channels.
 */
final class StaticPromotionSource implements PromotionSource
{
    public const PROMO_DE = '0190eeeeeeeeeeeeeeeeeeeeeeee7001';
    public const PROMO_SHARED = '0190eeeeeeeeeeeeeeeeeeeeeeee7002';
    public const PROMO_EN = '0190eeeeeeeeeeeeeeeeeeeeeeee7003';
    public const DISCOUNT_DE = '0190eeeeeeeeeeeeeeeeeeeeeeee8001';
    public const DISCOUNT_SHARED = '0190eeeeeeeeeeeeeeeeeeeeeeee8002';
    public const ASSIGNMENT_DE = '0190eeeeeeeeeeeeeeeeeeeeeeee9001';
    public const ASSIGNMENT_SHARED_EN = '0190eeeeeeeeeeeeeeeeeeeeeeee9002';

    public function channels(string $promotionId): ?array
    {
        return [
            self::PROMO_DE => [Ids::DE],
            self::PROMO_SHARED => [Ids::DE, Ids::EN],
            self::PROMO_EN => [Ids::EN],
        ][$promotionId] ?? null;
    }

    public function promotionOf(string $entity, string $id): ?string
    {
        return [
            self::DISCOUNT_DE => self::PROMO_DE,
            self::DISCOUNT_SHARED => self::PROMO_SHARED,
            self::ASSIGNMENT_DE => self::PROMO_DE,
            self::ASSIGNMENT_SHARED_EN => self::PROMO_SHARED,
        ][$id] ?? null;
    }

    public function assignmentChannel(string $id): ?string
    {
        return [self::ASSIGNMENT_DE => Ids::DE, self::ASSIGNMENT_SHARED_EN => Ids::EN][$id] ?? null;
    }
}
