<?php declare(strict_types=1);

namespace Mn\SalesChannelScope\Tests\Unit\Category;

use Mn\SalesChannelScope\Category\CategoryTrees;
use Mn\SalesChannelScope\Tests\Ids;
use Mn\SalesChannelScope\Tests\StaticCategoryTreeSource as T;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Filter\ContainsFilter;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Filter\EqualsAnyFilter;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Filter\OrFilter;

#[CoversClass(CategoryTrees::class)]
class CategoryTreesTest extends TestCase
{
    public function testACategoryBelongsToEveryChannelWhoseTreeHoldsIt(): void
    {
        $trees = T::trees();

        static::assertSame([Ids::DE], $trees->owners(T::DE_SHOES));
        static::assertSame([Ids::DE, Ids::EN], $trees->owners(T::SHARED_SALE));
        static::assertSame([], $trees->owners(T::ROOT), 'above every entry');
        static::assertSame([], $trees->owners(T::ORPHAN));
        static::assertNull($trees->owners('0190cccccccccccccccccccccccccc99'));
    }

    public function testOnlyCategoriesOfTheUsersChannelsAloneAreEditable(): void
    {
        $trees = T::trees();

        static::assertTrue($trees->isEditable(T::DE_NAV, [Ids::DE]));
        static::assertTrue($trees->isEditable(T::DE_SHOES, [Ids::DE]));
        static::assertFalse($trees->isEditable(T::SHARED_SALE, [Ids::DE]), 'shared with EN');
        static::assertTrue($trees->isEditable(T::SHARED_SALE, [Ids::DE, Ids::EN]));
        static::assertFalse($trees->isEditable(T::ROOT, [Ids::DE]), 'ancestors are read-only');
        static::assertFalse($trees->isEditable(T::EN_SOFAS, [Ids::DE]));
    }

    public function testVisibleAreTheUsersTreesAndTheirAncestors(): void
    {
        $trees = T::trees();

        foreach ([T::ROOT, T::DE_NAV, T::DE_SHOES, T::SHARED, T::SHARED_SALE] as $id) {
            static::assertTrue($trees->isVisible($id, [Ids::DE]), $id);
        }
        static::assertFalse($trees->isVisible(T::EN_NAV, [Ids::DE]));
        static::assertFalse($trees->isVisible(T::EN_SOFAS, [Ids::DE]));
        static::assertFalse($trees->isVisible(T::ORPHAN, [Ids::DE]), 'trees outside every channel are hidden');
    }

    public function testTheReadFilterCoversEntriesTheirAncestorsAndTheirSubtrees(): void
    {
        $filter = T::trees()->readFilter([Ids::DE]);

        static::assertInstanceOf(OrFilter::class, $filter);
        $queries = $filter->getQueries();
        static::assertInstanceOf(EqualsAnyFilter::class, $queries[0]);
        static::assertEqualsCanonicalizing([T::DE_NAV, T::SHARED, T::ROOT], $queries[0]->getValue());
        static::assertEquals([new ContainsFilter('path', '|' . T::DE_NAV . '|'), new ContainsFilter('path', '|' . T::SHARED . '|')], \array_slice($queries, 1));
    }

    public function testForeignEntriesBelowACategoryAreFound(): void
    {
        $trees = T::trees();

        static::assertTrue($trees->hasForeignEntriesBelow(T::ROOT, [Ids::DE]));
        static::assertFalse($trees->hasForeignEntriesBelow(T::DE_NAV, [Ids::DE]));
        static::assertSame(['own' => [T::DE_NAV, T::SHARED], 'foreign' => [T::EN_NAV, T::SHARED]], $trees->entryPointsFor([Ids::DE]));
    }
}
