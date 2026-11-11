<?php declare(strict_types=1);

namespace Mn\SalesChannelScope\Tests\Unit\Subscriber;

use Mn\SalesChannelScope\Entity\AclRoleSalesChannelDefinition;
use Mn\SalesChannelScope\Exception\ChannelScopeException;
use Mn\SalesChannelScope\Subscriber\WriteGuardSubscriber;
use Mn\SalesChannelScope\Tests\Ids;
use Mn\SalesChannelScope\Tests\StaticScopeLookup;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Shopware\Core\Checkout\Customer\Aggregate\CustomerAddress\CustomerAddressDefinition;
use Shopware\Core\Checkout\Customer\CustomerDefinition;
use Shopware\Core\Checkout\Order\Aggregate\OrderLineItem\OrderLineItemDefinition;
use Shopware\Core\Checkout\Order\OrderDefinition;
use Shopware\Core\Content\Product\Aggregate\ProductPrice\ProductPriceDefinition;
use Shopware\Core\Content\Product\Aggregate\ProductReview\ProductReviewDefinition;
use Shopware\Core\Content\Product\Aggregate\ProductVisibility\ProductVisibilityDefinition;
use Shopware\Core\Content\Product\ProductDefinition;
use Shopware\Core\Defaults;
use Shopware\Core\Framework\Context;
use Shopware\Core\Framework\DataAbstractionLayer\EntityDefinition;
use Shopware\Core\Framework\DataAbstractionLayer\Write\Command\DeleteCommand;
use Shopware\Core\Framework\DataAbstractionLayer\Write\Command\InsertCommand;
use Shopware\Core\Framework\DataAbstractionLayer\Write\Command\UpdateCommand;
use Shopware\Core\Framework\DataAbstractionLayer\Write\Command\WriteCommand;
use Shopware\Core\Framework\DataAbstractionLayer\Write\EntityExistence;
use Shopware\Core\Framework\DataAbstractionLayer\Write\Validation\PreWriteValidationEvent;
use Shopware\Core\Framework\DataAbstractionLayer\Write\WriteContext;
use Shopware\Core\Framework\Uuid\Uuid;
use Shopware\Core\System\SalesChannel\SalesChannelDefinition;
use Shopware\Core\System\SystemConfig\SystemConfigDefinition;
use Shopware\Core\System\SalesChannel\Aggregate\SalesChannelCountry\SalesChannelCountryDefinition;
use Shopware\Core\System\SalesChannel\Aggregate\SalesChannelAnalytics\SalesChannelAnalyticsDefinition;
use Shopware\Core\Content\LandingPage\LandingPageDefinition;
use Shopware\Core\Content\LandingPage\Aggregate\LandingPageSalesChannel\LandingPageSalesChannelDefinition;
use Shopware\Core\Content\Product\SalesChannel\Sorting\ProductSortingDefinition;
use Shopware\Core\Framework\DataAbstractionLayer\Write\EntityWriteGatewayInterface;
use Shopware\Core\Test\Stub\DataAbstractionLayer\StaticDefinitionInstanceRegistry;
use Symfony\Component\Validator\Validation;

#[CoversClass(WriteGuardSubscriber::class)]
class WriteGuardSubscriberTest extends TestCase
{
    private const ORDER_DE = '0190dddddddddddddddddddddddddd01';
    private const ORDER_EN = '0190dddddddddddddddddddddddddd02';
    private const ITEM_DE = '0190eeeeeeeeeeeeeeeeeeeeeeeeee01';
    private const ITEM_EN = '0190eeeeeeeeeeeeeeeeeeeeeeeeee02';
    private const CUSTOMER_DE = '0190ffffffffffffffffffffffffff01';
    private const CUSTOMER_EN = '0190ffffffffffffffffffffffffff02';
    private const CUSTOMER_EN_BOUND_DE = '0190ffffffffffffffffffffffffff03';
    private const ADDRESS_EN = '0190999999999999999999999999ff02';
    private const PRODUCT_DE = '0190777777777777777777777777aa01';
    private const PRODUCT_SHARED = '0190777777777777777777777777aa02';
    private const PRODUCT_EN = '0190777777777777777777777777aa03';
    private const PRICE_SHARED = '0190666666666666666666666666aa02';
    private const VISIBILITY_SHARED_DE = '0190555555555555555555555555aa01';
    private const VISIBILITY_SHARED_EN = '0190555555555555555555555555aa02';
    private const REVIEW_EN = '0190444444444444444444444444aa03';
    private const CONFIG_DE = '0190333333333333333333333333aa01';
    private const CONFIG_EN = '0190333333333333333333333333aa02';
    private const CONFIG_ALL = '0190333333333333333333333333aa00';
    private const CONFIG_DE_LISTING = '0190333333333333333333333333aa03';
    private const LANDING_DE = '0190222222222222222222222222aa01';
    private const ANALYTICS_DE = '0190111111111111111111111111aa01';
    private const ANALYTICS_EN = '0190111111111111111111111111aa02';
    private const LANDING_SHARED = '0190222222222222222222222222aa02';

    public function testOrdersOfTheUsersChannelCanChange(): void
    {
        static::assertSame([], $this->denied([$this->update($this->definition(OrderDefinition::class), self::ORDER_DE, ['custom_fields' => '{}'])]));
    }

    public function testOrdersOfOtherChannelsCanNotChange(): void
    {
        static::assertSame(['order'], $this->denied([$this->update($this->definition(OrderDefinition::class), self::ORDER_EN, ['custom_fields' => '{}'])]));
        static::assertSame(['order'], $this->denied([$this->delete($this->definition(OrderDefinition::class), self::ORDER_EN)]));
    }

    public function testOrdersCanNotMoveToOtherChannels(): void
    {
        $move = $this->update($this->definition(OrderDefinition::class), self::ORDER_DE, ['sales_channel_id' => Uuid::fromHexToBytes(Ids::EN)]);

        static::assertSame(['order'], $this->denied([$move]));
    }

    public function testOrdersAreOnlyCreatedInTheUsersChannels(): void
    {
        $id = Uuid::randomHex();
        static::assertSame([], $this->denied([$this->insert($this->definition(OrderDefinition::class), $id, ['sales_channel_id' => Uuid::fromHexToBytes(Ids::DE)])]));
        static::assertSame(['order'], $this->denied([$this->insert($this->definition(OrderDefinition::class), $id, ['sales_channel_id' => Uuid::fromHexToBytes(Ids::EN)])]));
    }

    public function testOrderDetailsFollowTheirOrder(): void
    {
        static::assertSame([], $this->denied([$this->update($this->definition(OrderLineItemDefinition::class), self::ITEM_DE, ['label' => 'x'])]));
        static::assertSame(['order_line_item'], $this->denied([$this->update($this->definition(OrderLineItemDefinition::class), self::ITEM_EN, ['label' => 'x'])]));
        static::assertSame(['order_line_item'], $this->denied([
            $this->insert($this->definition(OrderLineItemDefinition::class), Uuid::randomHex(), ['order_id' => Uuid::fromHexToBytes(self::ORDER_EN)]),
        ]));
    }

    public function testDetailsOfAnOrderInsertedInTheSameWriteAreChecked(): void
    {
        // e.g. a new order version: the order and its items are inserted together
        $orderId = Uuid::randomHex();
        $order = $this->insert($this->definition(OrderDefinition::class), $orderId, ['sales_channel_id' => Uuid::fromHexToBytes(Ids::DE)]);
        $item = $this->insert($this->definition(OrderLineItemDefinition::class), Uuid::randomHex(), ['order_id' => Uuid::fromHexToBytes($orderId)]);

        static::assertSame([], $this->denied([$order, $item]));
    }

    public function testCustomersRegisteredOrBoundInTheUsersChannelsCanChange(): void
    {
        static::assertSame([], $this->denied([$this->update($this->definition(CustomerDefinition::class), self::CUSTOMER_DE, ['title' => 'x'])]));
        static::assertSame([], $this->denied([$this->update($this->definition(CustomerDefinition::class), self::CUSTOMER_EN_BOUND_DE, ['title' => 'x'])]));
        static::assertSame(['customer'], $this->denied([$this->update($this->definition(CustomerDefinition::class), self::CUSTOMER_EN, ['title' => 'x'])]));
    }

    public function testCustomersCanNotBeRegisteredOrBoundElsewhere(): void
    {
        static::assertSame(['customer'], $this->denied([
            $this->update($this->definition(CustomerDefinition::class), self::CUSTOMER_DE, ['sales_channel_id' => Uuid::fromHexToBytes(Ids::EN)]),
        ]));
        static::assertSame(['customer'], $this->denied([
            $this->update($this->definition(CustomerDefinition::class), self::CUSTOMER_DE, ['bound_sales_channel_id' => Uuid::fromHexToBytes(Ids::EN)]),
        ]));
        static::assertSame([], $this->denied([
            $this->update($this->definition(CustomerDefinition::class), self::CUSTOMER_DE, ['bound_sales_channel_id' => null]),
        ]));
    }

    public function testAddressesFollowTheirCustomer(): void
    {
        static::assertSame(['customer_address'], $this->denied([$this->update($this->definition(CustomerAddressDefinition::class), self::ADDRESS_EN, ['city' => 'x'])]));

        $customerId = Uuid::randomHex();
        $customer = $this->insert($this->definition(CustomerDefinition::class), $customerId, ['sales_channel_id' => Uuid::fromHexToBytes(Ids::DE)]);
        $address = $this->insert($this->definition(CustomerAddressDefinition::class), Uuid::randomHex(), ['customer_id' => Uuid::fromHexToBytes($customerId)]);
        static::assertSame([], $this->denied([$customer, $address]), 'a new customer with its addresses');
    }

    public function testSalesChannelsAndRoleChannelsAreProtected(): void
    {
        static::assertSame([], $this->denied([$this->update($this->definition(SalesChannelDefinition::class), Ids::DE, ['active' => 1])]));
        static::assertSame(['sales_channel'], $this->denied([$this->update($this->definition(SalesChannelDefinition::class), Ids::EN, ['active' => 1])]));
        static::assertSame(['sales_channel'], $this->denied([$this->insert($this->definition(SalesChannelDefinition::class), Uuid::randomHex(), [])]));
        static::assertSame(['mn_acl_role_sales_channel'], $this->denied([
            new InsertCommand($this->definition(AclRoleSalesChannelDefinition::class), [], ['acl_role_id' => 'r', 'sales_channel_id' => 's'], $this->existence('mn_acl_role_sales_channel', false), ''),
        ]));
    }

    public function testProductsSoldOnlyInTheUsersChannelsCanChange(): void
    {
        static::assertSame([], $this->denied([$this->update($this->definition(ProductDefinition::class), self::PRODUCT_DE, ['stock' => 5])]));
        static::assertSame(['product'], $this->denied([$this->update($this->definition(ProductDefinition::class), self::PRODUCT_SHARED, ['stock' => 5])]), 'shared products are read-only');
        static::assertSame(['product'], $this->denied([$this->delete($this->definition(ProductDefinition::class), self::PRODUCT_SHARED)]));
        static::assertSame(['product_price'], $this->denied([$this->update($this->definition(ProductPriceDefinition::class), self::PRICE_SHARED, ['price' => '[]'])]));
    }

    public function testANewProductNeedsAVisibilityInTheUsersChannels(): void
    {
        $id = Uuid::randomHex();
        $product = $this->insert($this->definition(ProductDefinition::class), $id, ['product_number' => 'X']);
        $own = $this->insert($this->definition(ProductVisibilityDefinition::class), Uuid::randomHex(), ['product_id' => Uuid::fromHexToBytes($id), 'sales_channel_id' => Uuid::fromHexToBytes(Ids::DE)]);
        $other = $this->insert($this->definition(ProductVisibilityDefinition::class), Uuid::randomHex(), ['product_id' => Uuid::fromHexToBytes($id), 'sales_channel_id' => Uuid::fromHexToBytes(Ids::EN)]);
        $price = $this->insert($this->definition(ProductPriceDefinition::class), Uuid::randomHex(), ['product_id' => Uuid::fromHexToBytes($id)]);

        static::assertSame([], $this->denied([$product, $own, $price]));
        static::assertSame(['product'], $this->denied([$product]), 'no visibility');
        static::assertSame(['product_visibility'], $this->denied([$product, $own, $other]), 'not in other channels');
    }

    public function testVariantsNeedAParentTheUserOwns(): void
    {
        $variant = fn (string $parent) => $this->insert($this->definition(ProductDefinition::class), Uuid::randomHex(), ['parent_id' => Uuid::fromHexToBytes($parent)]);

        static::assertSame([], $this->denied([$variant(self::PRODUCT_DE)]));
        static::assertSame(['product'], $this->denied([$variant(self::PRODUCT_SHARED)]));
    }

    public function testOwnChannelVisibilitiesOfSharedProductsCanChange(): void
    {
        $visibility = $this->definition(ProductVisibilityDefinition::class);

        static::assertSame([], $this->denied([$this->update($visibility, self::VISIBILITY_SHARED_DE, ['visibility' => 10])]));
        static::assertSame([], $this->denied([$this->delete($visibility, self::VISIBILITY_SHARED_DE)]));
        static::assertSame(['product_visibility'], $this->denied([$this->delete($visibility, self::VISIBILITY_SHARED_EN)]));
        static::assertSame(['product_visibility'], $this->denied([
            $this->insert($visibility, Uuid::randomHex(), ['product_id' => Uuid::fromHexToBytes(self::PRODUCT_EN), 'sales_channel_id' => Uuid::fromHexToBytes(Ids::DE)]),
        ]), 'hidden products stay hidden');
        static::assertSame([], $this->denied([
            $this->update($this->definition(ProductDefinition::class), self::PRODUCT_SHARED, ['updated_at' => '2026-11-10 12:00:00.000']),
        ]), 'touching a shared product through a nested write of its own visibility');
    }

    public function testReviewsOfOtherChannelsCanNotChange(): void
    {
        static::assertSame(['product_review'], $this->denied([$this->update($this->definition(ProductReviewDefinition::class), self::REVIEW_EN, ['status' => 1])]));
    }

    public function testOnlySettingsOfTheUsersChannelsCanChange(): void
    {
        $config = $this->definition(SystemConfigDefinition::class);

        $cart = ['configuration_key' => 'core.cart.maxQuantity'];

        static::assertSame([], $this->denied([$this->update($config, self::CONFIG_DE, ['configuration_value' => '{}'])]));
        static::assertSame(['system_config'], $this->denied([$this->update($config, self::CONFIG_EN, ['configuration_value' => '{}'])]));
        static::assertSame(['system_config'], $this->denied([$this->delete($config, self::CONFIG_ALL)]));
        static::assertSame([], $this->denied([$this->insert($config, Uuid::randomHex(), $cart + ['sales_channel_id' => Uuid::fromHexToBytes(Ids::DE)])]));
        static::assertSame(['system_config'], $this->denied([$this->insert($config, Uuid::randomHex(), $cart + ['sales_channel_id' => null])]));
        static::assertSame(['system_config'], $this->denied([$this->update($config, self::CONFIG_DE, ['sales_channel_id' => Uuid::fromHexToBytes(Ids::EN)])]));
    }

    public function testOnlySettingsOfThePresetPagesCanChange(): void
    {
        $config = $this->definition(SystemConfigDefinition::class);
        $de = ['sales_channel_id' => Uuid::fromHexToBytes(Ids::DE)];

        static::assertSame(['system_config'], $this->denied([$this->insert($config, Uuid::randomHex(), $de + ['configuration_key' => 'core.mailerSettings.password'])]));
        static::assertSame(['system_config'], $this->denied([$this->insert($config, Uuid::randomHex(), $de + ['configuration_key' => 'SwagPayPal.settings.clientSecret'])]));
        static::assertSame(['system_config'], $this->denied([$this->update($config, self::CONFIG_DE_LISTING, ['configuration_value' => '{}'])]));
    }

    public function testOnlyLandingPagesOfTheUsersChannelsCanChange(): void
    {
        $landing = $this->definition(LandingPageDefinition::class);
        $mapping = $this->definition(LandingPageSalesChannelDefinition::class);
        $live = Uuid::fromHexToBytes(Defaults::LIVE_VERSION);
        $map = fn (string $page, string $channel) => new InsertCommand($mapping, [], ['landing_page_id' => Uuid::fromHexToBytes($page), 'landing_page_version_id' => $live, 'sales_channel_id' => Uuid::fromHexToBytes($channel)], $this->existence('landing_page_sales_channel', false), '');

        static::assertSame([], $this->denied([$this->update($landing, self::LANDING_DE, ['active' => 1])]));
        static::assertSame(['landing_page'], $this->denied([$this->update($landing, self::LANDING_SHARED, ['active' => 1])]));
        static::assertSame(['landing_page'], $this->denied([$this->delete($landing, self::LANDING_SHARED)]));
        static::assertSame(['landing_page_sales_channel'], $this->denied([$map(self::LANDING_DE, Ids::EN)]));

        $new = Uuid::randomHex();
        static::assertSame([], $this->denied([$this->insert($landing, $new, ['active' => 1]), $map($new, Ids::DE)]));
        static::assertSame(['landing_page_sales_channel'], $this->denied([$this->insert($landing, $new, ['active' => 1]), $map($new, Ids::EN)]));
    }

    public function testWhatAChannelOffersOnlyChangesForTheUsersChannels(): void
    {
        $country = $this->definition(SalesChannelCountryDefinition::class);
        $key = fn (string $channel) => ['sales_channel_id' => Uuid::fromHexToBytes($channel), 'country_id' => Uuid::fromHexToBytes('0190000000000000000000000000c001')];
        $insert = fn (string $channel) => new InsertCommand($country, [], $key($channel), $this->existence('sales_channel_country', false), '');
        $delete = fn (string $channel) => new DeleteCommand($country, $key($channel), $this->existence('sales_channel_country', true));

        static::assertSame([], $this->denied([$insert(Ids::DE), $delete(Ids::DE)]));
        static::assertSame(['sales_channel_country', 'sales_channel_country'], $this->denied([$insert(Ids::EN), $delete(Ids::EN)]));
    }

    public function testAnalyticsOfOtherChannelsCanNotChange(): void
    {
        $analytics = $this->definition(SalesChannelAnalyticsDefinition::class);

        static::assertSame([], $this->denied([$this->update($analytics, self::ANALYTICS_DE, ['active' => 1])]));
        static::assertSame(['sales_channel_analytics'], $this->denied([$this->update($analytics, self::ANALYTICS_EN, ['active' => 1])]));
        static::assertSame([], $this->denied([$this->insert($analytics, Uuid::randomHex(), ['active' => 1])]));
    }

    public function testProductSortingsApplyToAllChannels(): void
    {
        static::assertSame(['product_sorting'], $this->denied([$this->update($this->definition(ProductSortingDefinition::class), Uuid::randomHex(), ['priority' => 1])]));
    }

    public function testUnrestrictedUsersAreNotChecked(): void
    {
        static::assertSame([], $this->denied([$this->delete($this->definition(OrderDefinition::class), self::ORDER_EN)], Ids::context(Ids::FREE_USER)));
    }

    /**
     * @param list<WriteCommand> $commands
     *
     * @return list<string> entities of the refused commands
     */
    private function denied(array $commands, ?Context $context = null): array
    {
        $lookup = new StaticScopeLookup(
            orders: [self::ORDER_DE => Ids::DE, self::ORDER_EN => Ids::EN],
            children: [self::ITEM_DE => self::ORDER_DE, self::ITEM_EN => self::ORDER_EN],
            customers: [
                self::CUSTOMER_DE => ['salesChannelId' => Ids::DE, 'boundSalesChannelId' => null],
                self::CUSTOMER_EN => ['salesChannelId' => Ids::EN, 'boundSalesChannelId' => null],
                self::CUSTOMER_EN_BOUND_DE => ['salesChannelId' => Ids::EN, 'boundSalesChannelId' => Ids::DE],
            ],
            addresses: [self::ADDRESS_EN => self::CUSTOMER_EN],
            products: [self::PRODUCT_DE => [Ids::DE], self::PRODUCT_SHARED => [Ids::DE, Ids::EN], self::PRODUCT_EN => [Ids::EN]],
            productChildren: [self::PRICE_SHARED => self::PRODUCT_SHARED],
            channelRecords: [
                self::VISIBILITY_SHARED_DE => ['product' => self::PRODUCT_SHARED, 'channel' => Ids::DE],
                self::VISIBILITY_SHARED_EN => ['product' => self::PRODUCT_SHARED, 'channel' => Ids::EN],
            ],
            reviews: [self::REVIEW_EN => Ids::EN],
            settings: [self::CONFIG_DE => Ids::DE, self::CONFIG_EN => Ids::EN, self::CONFIG_ALL => '', self::CONFIG_DE_LISTING => Ids::DE],
            analytics: [self::ANALYTICS_DE => [Ids::DE], self::ANALYTICS_EN => [Ids::EN]],
            landingPages: [self::LANDING_DE => [Ids::DE], self::LANDING_SHARED => [Ids::DE, Ids::EN]],
            configKeys: [
                self::CONFIG_DE => 'core.cart.maxQuantity',
                self::CONFIG_EN => 'core.cart.maxQuantity',
                self::CONFIG_ALL => 'core.cart.maxQuantity',
                self::CONFIG_DE_LISTING => 'core.listing.productsPerPage',
            ],
        );
        $event = new PreWriteValidationEvent(WriteContext::createFromContext($context ?? Ids::context()), $commands);
        (new WriteGuardSubscriber(Ids::scope(), $lookup))->onPreWrite($event);

        $entities = [];
        foreach ($event->getExceptions()->getExceptions() as $exception) {
            static::assertInstanceOf(ChannelScopeException::class, $exception);
            $entities[] = $exception->getParameters()['entity'];
        }

        return $entities;
    }

    /**
     * @param class-string<EntityDefinition> $class
     */
    private function definition(string $class): EntityDefinition
    {
        static $registry = null;
        $registry ??= new StaticDefinitionInstanceRegistry(
            [OrderDefinition::class, OrderLineItemDefinition::class, CustomerDefinition::class, CustomerAddressDefinition::class, SalesChannelDefinition::class, AclRoleSalesChannelDefinition::class, ProductDefinition::class, ProductPriceDefinition::class, ProductVisibilityDefinition::class, ProductReviewDefinition::class, SystemConfigDefinition::class, ProductSortingDefinition::class, LandingPageDefinition::class, LandingPageSalesChannelDefinition::class, SalesChannelCountryDefinition::class, SalesChannelAnalyticsDefinition::class],
            Validation::createValidator(),
            $this->createMock(EntityWriteGatewayInterface::class),
        );

        return $registry->get($class);
    }

    /**
     * @param array<string, mixed> $payload
     */
    private function insert(EntityDefinition $definition, string $id, array $payload): InsertCommand
    {
        return new InsertCommand($definition, $payload, $this->key($id), $this->existence($definition->getEntityName(), false), '');
    }

    /**
     * @param array<string, mixed> $payload
     */
    private function update(EntityDefinition $definition, string $id, array $payload): UpdateCommand
    {
        return new UpdateCommand($definition, $payload, $this->key($id), $this->existence($definition->getEntityName(), true), '');
    }

    private function delete(EntityDefinition $definition, string $id): DeleteCommand
    {
        return new DeleteCommand($definition, $this->key($id), $this->existence($definition->getEntityName(), true));
    }

    /**
     * @return array<string, string>
     */
    private function key(string $id): array
    {
        return ['id' => Uuid::fromHexToBytes($id), 'version_id' => Uuid::fromHexToBytes(Defaults::LIVE_VERSION)];
    }

    private function existence(string $entity, bool $exists): EntityExistence
    {
        return new EntityExistence($entity, [], $exists, false, false, []);
    }
}
