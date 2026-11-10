<?php declare(strict_types=1);

namespace Mn\SalesChannelScope\Tests\Unit\Subscriber;

use Mn\SalesChannelScope\Subscriber\ChannelTotalsSubscriber;
use Mn\SalesChannelScope\Tests\Ids;
use Mn\SalesChannelScope\Totals\ChannelTotals;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Shopware\Core\Checkout\Customer\CustomerDefinition;
use Shopware\Core\Checkout\Customer\CustomerEntity;
use Shopware\Core\Content\Product\ProductDefinition;
use Shopware\Core\Content\Product\ProductEntity;
use Shopware\Core\Framework\Context;
use Shopware\Core\Framework\DataAbstractionLayer\Event\EntityLoadedEvent;
use Shopware\Core\Framework\DataAbstractionLayer\PartialEntity;

#[CoversClass(ChannelTotalsSubscriber::class)]
class ChannelTotalsSubscriberTest extends TestCase
{
    private const CUSTOMER = '0190ffffffffffffffffffffffffff01';
    private const PARENT = '0190777777777777777777777777aa01';
    private const VARIANT = '0190777777777777777777777777aa02';

    public function testCustomerTotalsAreCountedInTheUsersChannels(): void
    {
        $customer = $this->customer();
        $this->subscriber()->onCustomers(new EntityLoadedEvent(new CustomerDefinition(), [$customer], Ids::context()));

        static::assertSame(2, $customer->getOrderCount());
        static::assertSame(150.5, $customer->getOrderTotalAmount());
        static::assertSame('2026-11-01', $customer->getLastOrderDate()?->format('Y-m-d'));
        static::assertSame(1, $customer->getReviewCount());
    }

    public function testUnrestrictedUsersSeeShopwaresTotals(): void
    {
        $customer = $this->customer();
        $this->subscriber()->onCustomers(new EntityLoadedEvent(new CustomerDefinition(), [$customer], Ids::context(Ids::FREE_USER)));

        static::assertSame(9, $customer->getOrderCount());
        static::assertSame(999.0, $customer->getOrderTotalAmount());
    }

    public function testProductRatingAndSalesAreCountedInTheUsersChannels(): void
    {
        $parent = new ProductEntity();
        $parent->setId(self::PARENT);
        $parent->setRatingAverage(4.8);
        $parent->setSales(500);
        $variant = new ProductEntity();
        $variant->setId(self::VARIANT);
        $variant->setParentId(self::PARENT);
        $variant->setSales(80);

        $this->subscriber()->onProducts(new EntityLoadedEvent(new ProductDefinition(), [$parent, $variant], Ids::context()));

        static::assertSame(3.5, $parent->getRatingAverage());
        static::assertSame(12, $parent->getSales());
        static::assertNull($variant->getRatingAverage(), 'variants keep inheriting the rating');
        static::assertSame(0, $variant->getSales(), 'no sales in the users channels');
    }

    public function testPartialEntitiesOnlyGetTheLoadedFields(): void
    {
        $partial = new PartialEntity();
        $partial->assign(['id' => self::CUSTOMER, 'orderCount' => 9]);

        $this->subscriber()->onCustomers(new EntityLoadedEvent(new CustomerDefinition(), [$partial], Ids::context()));

        static::assertSame(2, $partial->get('orderCount'));
        static::assertFalse($partial->has('orderTotalAmount'));
    }

    private function customer(): CustomerEntity
    {
        $customer = new CustomerEntity();
        $customer->setId(self::CUSTOMER);
        $customer->setOrderCount(9);
        $customer->setOrderTotalAmount(999.0);
        $customer->setReviewCount(4);

        return $customer;
    }

    private function subscriber(): ChannelTotalsSubscriber
    {
        $totals = new class implements ChannelTotals {
            public function customers(array $customerIds, array $salesChannelIds): array
            {
                TestCase::assertSame([Ids::DE], $salesChannelIds);

                return [ChannelTotalsSubscriberTest::customerId() => ['orderCount' => 2, 'orderTotalAmount' => 150.5, 'lastOrderDate' => new \DateTimeImmutable('2026-11-01'), 'reviewCount' => 1]];
            }

            public function ratings(array $rootIds, array $salesChannelIds): array
            {
                return [ChannelTotalsSubscriberTest::parentId() => 3.5];
            }

            public function sales(array $productIds, array $salesChannelIds): array
            {
                return [ChannelTotalsSubscriberTest::parentId() => 12];
            }
        };

        return new ChannelTotalsSubscriber(Ids::scope(), $totals);
    }

    public static function customerId(): string
    {
        return self::CUSTOMER;
    }

    public static function parentId(): string
    {
        return self::PARENT;
    }
}
