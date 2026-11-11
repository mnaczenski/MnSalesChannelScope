<?php declare(strict_types=1);

namespace Mn\SalesChannelScope\Tests\Unit\Controller;

use Mn\SalesChannelScope\Category\CategoryTrees;
use Mn\SalesChannelScope\Cms\CmsPages;
use Mn\SalesChannelScope\Controller\ChannelScopeController;
use Mn\SalesChannelScope\Preset\PermissionPresets;
use Mn\SalesChannelScope\Preset\PresetDirectory;
use Mn\SalesChannelScope\Tests\Ids;
use Mn\SalesChannelScope\Tests\StaticCategoryTreeSource;
use Mn\SalesChannelScope\Tests\StaticCmsUsageSource;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Shopware\Core\Framework\Context;

#[CoversClass(ChannelScopeController::class)]
class ChannelScopeControllerTest extends TestCase
{
    public function testMeTellsTheAdministrationTheUsersChannels(): void
    {
        $me = $this->json($this->controller()->me(Ids::context()));

        static::assertTrue($me['restricted']);
        static::assertSame([Ids::DE], $me['salesChannelIds']);
        static::assertEqualsCanonicalizing([StaticCategoryTreeSource::DE_NAV, StaticCategoryTreeSource::SHARED], $me['categoryEntryPoints']['own']);

        $free = $this->json($this->controller()->me(Ids::context(Ids::FREE_USER)));
        static::assertFalse($free['restricted']);
        static::assertSame(['own' => [], 'foreign' => []], $free['categoryEntryPoints']);
    }

    public function testCmsPageSaysWhetherTheLayoutIsEditable(): void
    {
        static::assertSame(['restricted' => true, 'editable' => true, 'salesChannelIds' => [Ids::DE]], $this->json($this->controller()->cmsPage(StaticCmsUsageSource::DE_LAYOUT, Ids::context())));
        static::assertFalse($this->json($this->controller()->cmsPage(StaticCmsUsageSource::SHARED_LAYOUT, Ids::context()))['editable']);
        static::assertSame([], $this->json($this->controller()->cmsPage(StaticCmsUsageSource::EN_LAYOUT, Ids::context()))['salesChannelIds'], 'hidden layouts reveal no channels');
        static::assertTrue($this->json($this->controller()->cmsPage(StaticCmsUsageSource::EN_LAYOUT, Ids::context(Ids::FREE_USER)))['editable']);
    }

    public function testPresetsLeaveOutTheExpandedPrivileges(): void
    {
        $presets = $this->json($this->controller()->presets());

        static::assertSame('orders', $presets[0]['name']);
        static::assertSame(['name', 'label', 'description', 'roles', 'additionalPrivileges'], array_keys($presets[0]));
    }

    private function controller(): ChannelScopeController
    {
        $trees = new CategoryTrees(new StaticCategoryTreeSource());

        return new ChannelScopeController(
            Ids::scope(),
            $trees,
            new CmsPages(new StaticCmsUsageSource(), $trees),
            new PermissionPresets([new PresetDirectory(__DIR__ . '/../../../src/Resources/config/presets')]),
        );
    }

    /**
     * @return array<mixed>
     */
    private function json(\Symfony\Component\HttpFoundation\JsonResponse $response): array
    {
        return json_decode((string) $response->getContent(), true, 512, \JSON_THROW_ON_ERROR);
    }
}
