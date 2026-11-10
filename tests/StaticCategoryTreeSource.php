<?php declare(strict_types=1);

namespace Mn\SalesChannelScope\Tests;

use Mn\SalesChannelScope\Category\CategoryTrees;
use Mn\SalesChannelScope\Category\CategoryTreeSource;

/**
 * Trees for the tests:
 *
 *   ROOT ─┬─ DE_NAV ── DE_SHOES          (DE navigation)
 *         ├─ SHARED ── SHARED_SALE       (DE footer and EN footer)
 *         └─ EN_NAV ── EN_SOFAS          (EN navigation)
 *   ORPHAN                               (no channel)
 */
final class StaticCategoryTreeSource implements CategoryTreeSource
{
    public const ROOT = '0190cccccccccccccccccccccccccc00';
    public const DE_NAV = '0190cccccccccccccccccccccccccc01';
    public const DE_SHOES = '0190cccccccccccccccccccccccccc02';
    public const SHARED = '0190cccccccccccccccccccccccccc03';
    public const SHARED_SALE = '0190cccccccccccccccccccccccccc04';
    public const EN_NAV = '0190cccccccccccccccccccccccccc05';
    public const EN_SOFAS = '0190cccccccccccccccccccccccccc06';
    public const ORPHAN = '0190cccccccccccccccccccccccccc07';

    public function entryPoints(): array
    {
        return [
            Ids::DE => [self::DE_NAV, self::SHARED],
            Ids::EN => [self::EN_NAV, self::SHARED],
        ];
    }

    public function paths(array $categoryIds): array
    {
        $all = [
            self::ROOT => null,
            self::DE_NAV => '|' . self::ROOT . '|',
            self::DE_SHOES => '|' . self::ROOT . '|' . self::DE_NAV . '|',
            self::SHARED => '|' . self::ROOT . '|',
            self::SHARED_SALE => '|' . self::ROOT . '|' . self::SHARED . '|',
            self::EN_NAV => '|' . self::ROOT . '|',
            self::EN_SOFAS => '|' . self::ROOT . '|' . self::EN_NAV . '|',
            self::ORPHAN => null,
        ];

        return array_intersect_key($all, array_flip($categoryIds));
    }

    public static function trees(): CategoryTrees
    {
        return new CategoryTrees(new self());
    }
}
