<?php declare(strict_types=1);

namespace Mn\SalesChannelScope\Tests\Unit\Subscriber;

use Mn\SalesChannelScope\Scope\ScopeRules;
use Mn\SalesChannelScope\Subscriber\ReadFilterSubscriber;
use Mn\SalesChannelScope\Tests\Ids;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Mn\SalesChannelScope\Tests\StaticCategoryTreeSource;
use Mn\SalesChannelScope\Tests\StaticCmsUsageSource;
use Mn\SalesChannelScope\Category\CategoryTrees;
use Mn\SalesChannelScope\Cms\CmsPages;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Filter\PrefixFilter;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Filter\NotFilter;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Filter\AndFilter;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Filter\EqualsFilter;
use Shopware\Core\System\SystemConfig\SystemConfigDefinition;
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
                SystemConfigDefinition::class,
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

    public function testSettingsOfOtherChannelsAndHiddenDomainsAreHidden(): void
    {
        $filter = $this->search(SystemConfigDefinition::class)->getFilters()[0] ?? null;

        static::assertInstanceOf(AndFilter::class, $filter);
        [$channels, $core, $hidden] = $filter->getQueries();
        static::assertEquals(new OrFilter([
            new EqualsAnyFilter('salesChannelId', [Ids::DE]),
            new EqualsFilter('salesChannelId', null),
        ]), $channels);
        static::assertEquals(new PrefixFilter('configurationKey', 'core.'), $core);
        static::assertInstanceOf(NotFilter::class, $hidden);
        static::assertContainsEquals(new PrefixFilter('configurationKey', 'core.mailerSettings.'), $hidden->getQueries());
    }

    public function testLayoutPartsGoThroughTheirLayout(): void
    {
        $rules = new ScopeRules(null, new CmsPages(new StaticCmsUsageSource(), new CategoryTrees(new StaticCategoryTreeSource())));
        $filter = $rules->readFilter('cms_slot', [Ids::DE]);

        static::assertInstanceOf(EqualsAnyFilter::class, $filter);
        static::assertSame('block.section.pageId', $filter->getField());
        static::assertContains(StaticCmsUsageSource::OWNED_LAYOUT, $filter->getValue());
        static::assertNotContains(StaticCmsUsageSource::EN_LAYOUT, $filter->getValue());
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

    public function testProductsAreVisibleWhenSoldInTheUsersChannels(): void
    {
        $criteria = new Criteria();
        $criteria->addAssociation('children');
        $criteria->addAssociation('orderLineItems');

        $this->search(ProductDefinition::class, $criteria);

        $filter = $criteria->getFilters()[0] ?? null;
        static::assertInstanceOf(OrFilter::class, $filter);
        static::assertSame(['visibilities.salesChannelId', 'parent.visibilities.salesChannelId'], $this->fields($filter), 'variants count through their parent');

        $variants = $criteria->getAssociation('children')->getFilters()[0] ?? null;
        static::assertInstanceOf(OrFilter::class, $variants);
        static::assertEquals(
            [new EqualsAnyFilter('order.salesChannelId', [Ids::DE])],
            $criteria->getAssociation('orderLineItems')->getFilters(),
        );
    }

    public function testProductDetailsGoThroughTheirProduct(): void
    {
        $filter = (new ScopeRules())->readFilter('product_price', [Ids::DE]);

        static::assertInstanceOf(OrFilter::class, $filter);
        static::assertSame(['product.visibilities.salesChannelId', 'product.parent.visibilities.salesChannelId'], $this->fields($filter));
        static::assertEquals(new EqualsAnyFilter('salesChannelId', [Ids::DE]), (new ScopeRules())->readFilter('product_review', [Ids::DE]));
    }

    public function testEntitiesWithoutARuleStayUntouched(): void
    {
        static::assertNull((new ScopeRules())->readFilter('property_group', [Ids::DE]));
        static::assertNull((new ScopeRules())->readFilter('product_manufacturer', [Ids::DE]));
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
