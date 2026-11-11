<?php declare(strict_types=1);

namespace Mn\SalesChannelScope\Tests\Unit\Subscriber;

use Mn\SalesChannelScope\Exception\ChannelScopeException;
use Mn\SalesChannelScope\Subscriber\ChannelParameterGuardSubscriber;
use Mn\SalesChannelScope\Tests\Ids;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Shopware\Core\Framework\Context;
use Shopware\Core\Framework\DataAbstractionLayer\EntityRepository;
use Shopware\Core\Framework\DataAbstractionLayer\Search\IdSearchResult;
use Shopware\Core\PlatformRequest;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpKernel\Event\ControllerEvent;
use Symfony\Component\HttpKernel\HttpKernelInterface;

#[CoversClass(ChannelParameterGuardSubscriber::class)]
class ChannelParameterGuardSubscriberTest extends TestCase
{
    private const VISIBLE_CUSTOMER = '0190cccccccccccccccccccccccccc01';
    private const HIDDEN_CUSTOMER = '0190cccccccccccccccccccccccccc02';

    public function testChannelsInThePathMustBeOwn(): void
    {
        static::assertNull($this->deny('api.admin.product-stream-preview', attributes: ['salesChannelId' => Ids::DE]));
        static::assertNotNull($this->deny('api.admin.product-stream-preview', attributes: ['salesChannelId' => Ids::EN]));
        static::assertNotNull($this->deny('api.action.sales_channel_file.preview', attributes: ['salesChannelId' => Ids::EN]));
        static::assertNotNull($this->deny('api.action.sales_channel_file.list', attributes: []));
    }

    public function testChannelsInTheQueryMustBeOwn(): void
    {
        static::assertNull($this->deny('api.action.preview_search.search', query: ['salesChannelId' => strtoupper(Ids::DE)]));
        static::assertNotNull($this->deny('api.action.preview_search.search', query: ['salesChannelId' => Ids::EN]));
    }

    public function testSwitchingTheCustomerNeedsOwnChannelAndVisibleCustomer(): void
    {
        static::assertNull($this->deny('api.proxy.switch-customer', body: ['salesChannelId' => Ids::DE, 'customerId' => self::VISIBLE_CUSTOMER]));
        static::assertNotNull($this->deny('api.proxy.switch-customer', body: ['salesChannelId' => Ids::DE, 'customerId' => self::HIDDEN_CUSTOMER]));
        static::assertNotNull($this->deny('api.proxy.switch-customer', body: ['salesChannelId' => Ids::DE, 'customerId' => 'nope']));
        static::assertNotNull($this->deny('api.proxy.switch-customer', body: ['salesChannelId' => Ids::EN, 'customerId' => self::VISIBLE_CUSTOMER]));
    }

    public function testUnrestrictedUsersAndOtherRoutesAreNotChecked(): void
    {
        static::assertNull($this->deny('api.admin.product-stream-preview', attributes: ['salesChannelId' => Ids::EN], context: Ids::context(Ids::FREE_USER)));
        static::assertNull($this->deny('api.order.search', attributes: ['salesChannelId' => Ids::EN]));
    }

    /**
     * @param array<string, mixed> $attributes
     * @param array<string, mixed> $query
     * @param array<string, mixed> $body
     */
    private function deny(string $route, array $attributes = [], array $query = [], array $body = [], ?Context $context = null): ?ChannelScopeException
    {
        $request = new Request($query, $body);
        $request->attributes->add($attributes + ['_route' => $route, PlatformRequest::ATTRIBUTE_CONTEXT_OBJECT => $context ?? Ids::context()]);

        $customers = $this->createMock(EntityRepository::class);
        $customers->method('searchIds')->willReturnCallback(static function ($criteria) {
            $data = $criteria->getIds() === [self::VISIBLE_CUSTOMER] ? [self::VISIBLE_CUSTOMER => ['primaryKey' => self::VISIBLE_CUSTOMER, 'data' => []]] : [];

            return new IdSearchResult(\count($data), $data, $criteria, Ids::context());
        });

        try {
            (new ChannelParameterGuardSubscriber(Ids::scope(), $customers))->onController(new ControllerEvent($this->createMock(HttpKernelInterface::class), static fn () => null, $request, HttpKernelInterface::MAIN_REQUEST));
        } catch (ChannelScopeException $exception) {
            return $exception;
        }

        return null;
    }
}
