<?php declare(strict_types=1);

namespace Mn\SalesChannelScope\Tests\Unit\Subscriber;

use Mn\SalesChannelScope\Exception\ChannelScopeException;
use Mn\SalesChannelScope\Subscriber\ThemeGuardSubscriber;
use Mn\SalesChannelScope\Tests\Ids;
use Mn\SalesChannelScope\Tests\StaticScopeLookup;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Shopware\Core\Framework\Context;
use Shopware\Core\PlatformRequest;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpKernel\Event\ControllerEvent;
use Symfony\Component\HttpKernel\HttpKernelInterface;

#[CoversClass(ThemeGuardSubscriber::class)]
class ThemeGuardSubscriberTest extends TestCase
{
    private const THEME_DE = '0190999999999999999999999999dd01';
    private const THEME_SHARED = '0190999999999999999999999999dd02';

    public function testThemesAreOnlyAssignedToTheUsersChannels(): void
    {
        static::assertNull($this->deny(ThemeGuardSubscriber::ASSIGN_ROUTE, ['themeId' => self::THEME_SHARED, 'salesChannelId' => Ids::DE]));
        static::assertNotNull($this->deny(ThemeGuardSubscriber::ASSIGN_ROUTE, ['themeId' => self::THEME_DE, 'salesChannelId' => Ids::EN]));
    }

    public function testOnlyThemesOfTheUsersChannelsAreConfigured(): void
    {
        static::assertNull($this->deny('api.action.theme.update', ['themeId' => self::THEME_DE]));
        static::assertNotNull($this->deny('api.action.theme.update', ['themeId' => self::THEME_SHARED]));
        static::assertNotNull($this->deny('api.action.theme.reset', ['themeId' => '0190999999999999999999999999dd99']));
    }

    public function testUnrestrictedUsersAreNotChecked(): void
    {
        static::assertNull($this->deny('api.action.theme.update', ['themeId' => self::THEME_SHARED], Ids::context(Ids::FREE_USER)));
    }

    /**
     * @param array<string, string> $attributes
     */
    private function deny(string $route, array $attributes, ?Context $context = null): ?string
    {
        $request = new Request();
        $request->attributes->add($attributes + ['_route' => $route, PlatformRequest::ATTRIBUTE_CONTEXT_OBJECT => $context ?? Ids::context()]);
        $lookup = new StaticScopeLookup(themes: [self::THEME_DE => [Ids::DE], self::THEME_SHARED => [Ids::DE, Ids::EN]]);
        try {
            (new ThemeGuardSubscriber(Ids::scope(), $lookup))->onController(new ControllerEvent($this->createMock(HttpKernelInterface::class), static fn () => null, $request, HttpKernelInterface::MAIN_REQUEST));
        } catch (ChannelScopeException $exception) {
            return $exception->getMessage();
        }

        return null;
    }
}
