<?php declare(strict_types=1);

namespace Mn\SalesChannelScope\Tests\Unit\Subscriber;

use Mn\SalesChannelScope\Scope\ScopeRules;
use Mn\SalesChannelScope\Subscriber\ReadFilterSubscriber;
use Mn\SalesChannelScope\Tests\Ids;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Shopware\Core\Checkout\Customer\Aggregate\CustomerAddress\CustomerAddressDefinition;
use Shopware\Core\Checkout\Customer\CustomerDefinition;
use Shopware\Core\Checkout\Order\Aggregate\OrderCustomer\OrderCustomerDefinition;
use Shopware\Core\Checkout\Order\Aggregate\OrderLineItem\OrderLineItemDefinition;
use Shopware\Core\Checkout\Order\OrderDefinition;
use Shopware\Core\Content\Product\ProductDefinition;
use Shopware\Core\Framework\Context;
use Shopware\Core\Framework\DataAbstractionLayer\EntityDefinition;
use Shopware\Core\Framework\DataAbstractionLayer\Event\BeforeEntityAggregationEvent;
use Shopware\Core\Framework\DataAbstractionLayer\Event\EntitySearchedEvent;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Criteria;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Filter\EqualsAnyFilter;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Filter\Filter;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Filter\OrFilter;
use Shopware\Core\System\SalesChannel\SalesChannelDefinition;
use Shopware\Core\Test\Stub\DataAbstractionLayer\StaticDefinitionInstanceRegistry;
use Symfony\Component\Validator\Validation;
use Shopware\Core\Framework\DataAbstractionLayer\Write\EntityWriteGatewayInterface;

#[CoversClass(ReadFilterSubscriber::class)]
#[CoversClass(ScopeRules::class)]
class ReadFilterSubscriberTest extends TestCase
{
    private StaticDefinitionInstanceRegistry $registry;

    protected function setUp(): void
    {
        $this->registry = new StaticDefinitionInstanceRegistry(
            [
                OrderDefinition::class,
                OrderLineItemDefinition::class,
                CustomerDefinition::class,
                CustomerAddressDefinition::class,
                OrderCustomerDefinition::class,
                SalesChannelDefinition::class,
                ProductDefinition::class,
            ],
            Validation::createValidator(),
            $this->createMock(EntityWriteGatewayInterface::class),
        );
    }

    public function testOrdersAreLimitedToTheUsersChannels(): void
    {
        $criteria = $this->search(OrderDefinition::class);

        static::assertEquals([new EqualsAnyFilter('salesChannelId', [Ids::DE])], $criteria->getFilters());
    }

    public function testOrderDetailsGoThroughTheirOrder(): void
    {
        $criteria = $this->search(OrderLineItemDefinition::class);

        static::assertEquals([new EqualsAnyFilter('order.salesChannelId', [Ids::DE])], $criteria->getFilters());
    }

    public function testCustomersAreVisibleWhenRegisteredBoundOrOrdering(): void
    {
        $filter = $this->search(CustomerDefinition::class)->getFilters()[0] ?? null;

        static::assertInstanceOf(OrFilter::class, $filter);
        static::assertSame(
            ['salesChannelId', 'boundSalesChannelId', 'orderCustomers.order.salesChannelId'],
            $this->fields($filter),
        );
    }

    public function testToManyAssociationsAreFilteredToo(): void
    {
        $criteria = new Criteria();
        $criteria->addAssociation('addresses');
        $criteria->addAssociation('orderCustomers.order.lineItems');
        $criteria->addAssociation('defaultBillingAddress');

        $this->search(CustomerDefinition::class, $criteria);

        $addresses = $criteria->getAssociation('addresses')->getFilters()[0] ?? null;
        static::assertInstanceOf(OrFilter::class, $addresses);
        static::assertSame(
            ['customer.salesChannelId', 'customer.boundSalesChannelId', 'customer.orderCustomers.order.salesChannelId'],
            $this->fields($addresses),
        );
        static::assertEquals(
            [new EqualsAnyFilter('order.salesChannelId', [Ids::DE])],
            $criteria->getAssociation('orderCustomers')->getFilters(),
        );
        static::assertSame([], $criteria->getAssociation('orderCustomers')->getAssociation('order')->getFilters(), 'one-to-one joins take no filter');
        static::assertEquals(
            [new EqualsAnyFilter('order.salesChannelId', [Ids::DE])],
            $criteria->getAssociation('orderCustomers')->getAssociation('order')->getAssociation('lineItems')->getFilters(),
            'to-many associations below a join are filtered',
        );
        static::assertSame([], $criteria->getAssociation('defaultBillingAddress')->getFilters(), 'many-to-one joins take no filter');
    }

    public function testEntitiesWithoutARuleStayUntouched(): void
    {
        $criteria = new Criteria();
        $criteria->addAssociation('orderLineItems');

        $this->search(ProductDefinition::class, $criteria);

        static::assertSame([], $criteria->getFilters(), 'products come in iteration 2');
        static::assertEquals(
            [new EqualsAnyFilter('order.salesChannelId', [Ids::DE])],
            $criteria->getAssociation('orderLineItems')->getFilters(),
            'but their order line items are filtered',
        );
    }

    public function testAggregationsAreFiltered(): void
    {
        $criteria = new Criteria();
        $event = new BeforeEntityAggregationEvent($criteria, $this->registry->get(OrderDefinition::class), Ids::context());
        $this->subscriber()->onAggregate($event);

        static::assertEquals([new EqualsAnyFilter('salesChannelId', [Ids::DE])], $criteria->getFilters());
    }

    public function testUnrestrictedUsersSeeEverything(): void
    {
        $criteria = $this->search(OrderDefinition::class, null, Ids::context(Ids::FREE_USER));

        static::assertSame([], $criteria->getFilters());
    }

    /**
     * @param class-string<EntityDefinition> $definition
     */
    private function search(string $definition, ?Criteria $criteria = null, ?Context $context = null): Criteria
    {
        $criteria ??= new Criteria();
        $event = new EntitySearchedEvent($criteria, $this->registry->get($definition), $context ?? Ids::context());
        $this->subscriber()->onSearch($event);

        return $criteria;
    }

    private function subscriber(): ReadFilterSubscriber
    {
        return new ReadFilterSubscriber(Ids::scope(), new ScopeRules());
    }

    /**
     * @return list<string>
     */
    private function fields(Filter $filter): array
    {
        return array_values(array_map(static fn (Filter $query) => $query->getFields()[0], $filter->getQueries()));
    }
}
