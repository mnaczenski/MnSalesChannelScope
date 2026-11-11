<?php declare(strict_types=1);

namespace Mn\SalesChannelScope\Tests\Unit\Subscriber;

use Mn\SalesChannelScope\Exception\ChannelScopeException;
use Mn\SalesChannelScope\Subscriber\AdminSearchGuardSubscriber;
use Mn\SalesChannelScope\Tests\Ids;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Shopware\Core\Framework\Context;
use Shopware\Core\PlatformRequest;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpKernel\Event\ControllerEvent;
use Symfony\Component\HttpKernel\HttpKernelInterface;

#[CoversClass(AdminSearchGuardSubscriber::class)]
#[CoversClass(ChannelScopeException::class)]
class AdminSearchGuardSubscriberTest extends TestCase
{
    public function testLimitedUsersCanNotUseTheSearchIndex(): void
    {
        $exception = $this->deny(AdminSearchGuardSubscriber::ROUTE, Ids::context());

        static::assertInstanceOf(ChannelScopeException::class, $exception);
        static::assertSame(ChannelScopeException::ROUTE_DENIED, $exception->getErrorCode());
        static::assertSame(403, $exception->getStatusCode());
    }

    public function testUnrestrictedUsersAndOtherRoutesAreNotChecked(): void
    {
        static::assertNull($this->deny(AdminSearchGuardSubscriber::ROUTE, Ids::context(Ids::FREE_USER)));
        static::assertNull($this->deny('api.admin.search', Ids::context()), 'the database search stays available');
    }

    private function deny(string $route, Context $context): ?ChannelScopeException
    {
        $request = new Request();
        $request->attributes->add(['_route' => $route, PlatformRequest::ATTRIBUTE_CONTEXT_OBJECT => $context]);

        try {
            (new AdminSearchGuardSubscriber(Ids::scope()))->onController(new ControllerEvent($this->createMock(HttpKernelInterface::class), static fn () => null, $request, HttpKernelInterface::MAIN_REQUEST));
        } catch (ChannelScopeException $exception) {
            return $exception;
        }

        return null;
    }
}
