<?php declare(strict_types=1);

namespace Mn\SalesChannelScope\Tests\Unit\Scope;

use Mn\SalesChannelScope\Scope\ChannelScope;
use Mn\SalesChannelScope\Tests\Ids;
use Mn\SalesChannelScope\Tests\StaticRoleChannelLoader;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Shopware\Core\Framework\Api\Context\AdminApiSource;
use Shopware\Core\Framework\Api\Context\SalesChannelApiSource;
use Shopware\Core\Framework\Api\Context\SystemSource;
use Shopware\Core\Framework\Context;

#[CoversClass(ChannelScope::class)]
class ChannelScopeTest extends TestCase
{
    public function testARoleWithChannelsLimitsTheUser(): void
    {
        static::assertSame([Ids::DE], Ids::scope()->salesChannelIds(Ids::context()));
    }

    public function testTheChannelsOfAllRolesAddUp(): void
    {
        $scope = new ChannelScope(new StaticRoleChannelLoader([
            'u1' => ['r1' => [Ids::DE], 'r2' => [Ids::EN, strtoupper(Ids::DE)]],
        ]));

        static::assertSame([Ids::DE, Ids::EN], $scope->salesChannelIds(new Context(new AdminApiSource('u1'))));
    }

    public function testOneRoleWithoutChannelsRemovesTheLimit(): void
    {
        $scope = new ChannelScope(new StaticRoleChannelLoader([
            'u1' => ['r1' => [Ids::DE], 'r2' => []],
        ]));

        static::assertNull($scope->salesChannelIds(new Context(new AdminApiSource('u1'))));
    }

    public function testUnrestrictedSources(): void
    {
        $scope = Ids::scope();
        $admin = new AdminApiSource(Ids::DE_USER);
        $admin->setIsAdmin(true);

        static::assertNull($scope->salesChannelIds(new Context($admin)), 'admins');
        static::assertNull($scope->salesChannelIds(new Context(new AdminApiSource(null, 'integration-id'))), 'integrations');
        static::assertNull($scope->salesChannelIds(new Context(new SystemSource())), 'system source');
        static::assertNull($scope->salesChannelIds(new Context(new SalesChannelApiSource(Ids::DE))), 'storefront and Store API');
        static::assertNull($scope->salesChannelIds(Ids::context(Ids::FREE_USER)), 'roles without channels');
        static::assertNull($scope->salesChannelIds(Ids::context('0190cccccccccccccccccccccccccc01')), 'users without roles');
        static::assertTrue($scope->isRestricted(Ids::context()));
    }

    public function testSystemScopeIsNeverLimited(): void
    {
        $context = Ids::context();

        $context->scope(Context::SYSTEM_SCOPE, function (Context $context): void {
            static::assertNull(Ids::scope()->salesChannelIds($context));
        });
    }

    public function testRolesAreLoadedOncePerUserUntilReset(): void
    {
        $loader = new StaticRoleChannelLoader([Ids::DE_USER => ['r' => [Ids::DE]]]);
        $scope = new ChannelScope($loader);

        $scope->salesChannelIds(Ids::context());
        $scope->salesChannelIds(Ids::context());
        static::assertSame(1, $loader->calls);

        $scope->reset();
        $scope->salesChannelIds(Ids::context());
        static::assertSame(2, $loader->calls);
    }
}
