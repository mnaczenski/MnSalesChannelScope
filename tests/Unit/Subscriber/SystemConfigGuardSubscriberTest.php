<?php declare(strict_types=1);

namespace Mn\SalesChannelScope\Tests\Unit\Subscriber;

use Mn\SalesChannelScope\Exception\ChannelScopeException;
use Mn\SalesChannelScope\Scope\ConfigRules;
use Mn\SalesChannelScope\Subscriber\SystemConfigGuardSubscriber;
use Mn\SalesChannelScope\Tests\Ids;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Shopware\Core\Framework\Context;
use Shopware\Core\PlatformRequest;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpKernel\Event\ControllerEvent;
use Symfony\Component\HttpKernel\HttpKernelInterface;

#[CoversClass(SystemConfigGuardSubscriber::class)]
#[CoversClass(ConfigRules::class)]
class SystemConfigGuardSubscriberTest extends TestCase
{
    public function testReadsOwnAndInheritedCoreSettings(): void
    {
        static::assertNull($this->deny(SystemConfigGuardSubscriber::READ_ROUTE, ['domain' => 'core.cart', 'salesChannelId' => Ids::DE]));
        static::assertNull($this->deny(SystemConfigGuardSubscriber::READ_ROUTE, ['domain' => 'core.cart']));
        static::assertNull($this->deny(SystemConfigGuardSubscriber::READ_ROUTE, ['domain' => 'core.tax']));
    }

    public function testNoReadsOfOtherChannelsCredentialsOrPluginSettings(): void
    {
        static::assertNotNull($this->deny(SystemConfigGuardSubscriber::READ_ROUTE, ['domain' => 'core.cart', 'salesChannelId' => Ids::EN]));
        static::assertNotNull($this->deny(SystemConfigGuardSubscriber::READ_ROUTE, ['domain' => 'core.mailerSettings']));
        static::assertNotNull($this->deny(SystemConfigGuardSubscriber::READ_ROUTE, ['domain' => 'core.store']));
        static::assertNotNull($this->deny(SystemConfigGuardSubscriber::READ_ROUTE, ['domain' => 'SwagPayPal.settings', 'salesChannelId' => Ids::DE]));
    }

    public function testSavesOnlyPresetPagesOfOwnChannels(): void
    {
        static::assertNull($this->deny(SystemConfigGuardSubscriber::SAVE_ROUTE, ['salesChannelId' => Ids::DE], ['core.cart.maxQuantity' => 10]));
        static::assertNotNull($this->deny(SystemConfigGuardSubscriber::SAVE_ROUTE, ['salesChannelId' => Ids::EN], ['core.cart.maxQuantity' => 10]));
        static::assertNotNull($this->deny(SystemConfigGuardSubscriber::SAVE_ROUTE, [], ['core.cart.maxQuantity' => 10]));
        static::assertNotNull($this->deny(SystemConfigGuardSubscriber::SAVE_ROUTE, ['salesChannelId' => Ids::DE], ['core.listing.productsPerPage' => 10]));
    }

    public function testBatchSavesAreCheckedPerChannel(): void
    {
        static::assertNull($this->deny(SystemConfigGuardSubscriber::BATCH_ROUTE, [], [Ids::DE => ['core.basicInformation.email' => 'a@b.c']]));
        static::assertNotNull($this->deny(SystemConfigGuardSubscriber::BATCH_ROUTE, [], [Ids::DE => ['core.cart.maxQuantity' => 1], 'null' => ['core.cart.maxQuantity' => 1]]));
        static::assertNotNull($this->deny(SystemConfigGuardSubscriber::BATCH_ROUTE, [], [Ids::EN => ['core.cart.maxQuantity' => 1]]));
        static::assertNull($this->deny(SystemConfigGuardSubscriber::BATCH_ROUTE, [], ['null' => [], Ids::DE => ['core.cart.maxQuantity' => 1]]));
    }

    public function testUnrestrictedUsersAndOtherRoutesAreNotChecked(): void
    {
        static::assertNull($this->deny(SystemConfigGuardSubscriber::BATCH_ROUTE, [], ['null' => ['core.mailerSettings.password' => 'x']], Ids::context(Ids::FREE_USER)));
        static::assertNull($this->deny('api.some.other.route', ['domain' => 'core.mailerSettings']));
    }

    /**
     * @param array<string, string> $query
     * @param array<string, mixed> $body
     */
    private function deny(string $route, array $query, array $body = [], ?Context $context = null): ?string
    {
        $request = new Request($query, $body);
        $request->attributes->set('_route', $route);
        $request->attributes->set(PlatformRequest::ATTRIBUTE_CONTEXT_OBJECT, $context ?? Ids::context());
        $event = new ControllerEvent($this->createMock(HttpKernelInterface::class), static fn () => null, $request, HttpKernelInterface::MAIN_REQUEST);

        try {
            (new SystemConfigGuardSubscriber(Ids::scope()))->onController($event);
        } catch (ChannelScopeException $exception) {
            return $exception->getMessage();
        }

        return null;
    }
}
