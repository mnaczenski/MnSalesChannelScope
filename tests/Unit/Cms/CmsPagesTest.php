<?php declare(strict_types=1);

namespace Mn\SalesChannelScope\Tests\Unit\Cms;

use Mn\SalesChannelScope\Category\CategoryTrees;
use Mn\SalesChannelScope\Cms\CmsPages;
use Mn\SalesChannelScope\Tests\Ids;
use Mn\SalesChannelScope\Tests\StaticCategoryTreeSource;
use Mn\SalesChannelScope\Tests\StaticCmsUsageSource as Cms;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(CmsPages::class)]
class CmsPagesTest extends TestCase
{
    public function testChannelsComeFromUsersAndOwners(): void
    {
        $pages = $this->pages();

        static::assertSame([Ids::DE], $pages->channels(Cms::DE_LAYOUT));
        static::assertEqualsCanonicalizing([Ids::DE, Ids::EN], $pages->channels(Cms::SHARED_LAYOUT));
        static::assertSame([], $pages->channels(Cms::UNUSED_LAYOUT));
        static::assertNull($pages->channels('0190dddddddddddddddddddddddd9999'));
    }

    public function testVisibleLayoutsAreTheChannelsAndTheLockedOnes(): void
    {
        static::assertEqualsCanonicalizing(
            [Cms::DE_LAYOUT, Cms::SHARED_LAYOUT, Cms::OWNED_LAYOUT, Cms::LOCKED_LAYOUT],
            $this->pages()->visibleIds([Ids::DE]),
        );
    }

    public function testOnlyLayoutsOfTheUsersChannelsAreEditable(): void
    {
        $pages = $this->pages();

        static::assertTrue($pages->isEditable(Cms::DE_LAYOUT, [Ids::DE]));
        static::assertTrue($pages->isEditable(Cms::OWNED_LAYOUT, [Ids::DE]));
        static::assertFalse($pages->isEditable(Cms::SHARED_LAYOUT, [Ids::DE]));
        static::assertTrue($pages->isEditable(Cms::SHARED_LAYOUT, [Ids::DE, Ids::EN]));
        static::assertFalse($pages->isEditable(Cms::EN_LAYOUT, [Ids::DE]));
        static::assertFalse($pages->isEditable(Cms::UNUSED_LAYOUT, [Ids::DE]));
        static::assertFalse($pages->isEditable(Cms::LOCKED_LAYOUT, [Ids::DE, Ids::EN]));
    }

    private function pages(): CmsPages
    {
        return new CmsPages(new Cms(), new CategoryTrees(new StaticCategoryTreeSource()));
    }
}
