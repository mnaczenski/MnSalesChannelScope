<?php declare(strict_types=1);

/*
 * Test data for the leak check (tests/acceptance/leak-check.php) in a freshly
 * installed shop with two sales channels (Storefront and Headless):
 *
 * - a role limited to the Storefront channel that may read every entity,
 *   and a user with that role;
 * - records only of the Headless channel: a product, a customer with an
 *   address, a wishlist and a recovery link, an order with a delivery
 *   position, a payment capture and a refund and a state transition, a
 *   newsletter recipient, a promotion.
 *
 * The presets are added by the workflow afterwards
 * (bin/console mn:channel-scope:preset <preset> scope-ci). Prints the
 * variables the leak check reads as KEY=value lines:
 *
 *   php tests/ci/seed.php /path/to/shopware >> "$GITHUB_ENV"
 */

use Doctrine\DBAL\Connection;
use Shopware\Core\Checkout\Cart\Price\Struct\CalculatedPrice;
use Shopware\Core\Checkout\Cart\Price\Struct\CartPrice;
use Shopware\Core\Checkout\Cart\Tax\Struct\CalculatedTaxCollection;
use Shopware\Core\Checkout\Cart\Tax\Struct\TaxRuleCollection;
use Shopware\Core\Defaults;
use Shopware\Core\Framework\Adapter\Kernel\KernelFactory;
use Shopware\Core\Framework\Context;
use Shopware\Core\Framework\DataAbstractionLayer\EntityRepository;
use Shopware\Core\Framework\DataAbstractionLayer\Pricing\CashRoundingConfig;
use Shopware\Core\Framework\Uuid\Uuid;
use Symfony\Component\Dotenv\Dotenv;

$root = $argv[1] ?? dirname(__DIR__, 5);
$classLoader = require $root . '/vendor/autoload.php';
(new Dotenv())->usePutenv()->bootEnv($root . '/.env');
$kernel = KernelFactory::create(environment: getenv('APP_ENV') ?: 'prod', debug: false, classLoader: $classLoader);
assert($kernel instanceof Symfony\Component\HttpKernel\KernelInterface);
$kernel->boot();
$container = $kernel->getContainer();
$connection = $container->get(Connection::class);
assert($connection instanceof Connection);
$context = Context::createCLIContext();

$repository = static function (string $entity) use ($container): EntityRepository {
    $repository = $container->get($entity . '.repository');
    assert($repository instanceof EntityRepository);

    return $repository;
};
$hex = static fn (mixed $value): string => is_string($value) && $value !== '' ? strtolower($value) : throw new RuntimeException('Missing data in the installation.');
$one = static fn (string $sql, array $params = []): string => $hex($connection->fetchOne($sql, $params));
$channel = static fn (string $type): string => $one(
    'SELECT LOWER(HEX(id)) FROM sales_channel WHERE type_id = UNHEX(:type) ORDER BY created_at LIMIT 1',
    ['type' => $type],
);
$initialState = static fn (string $machine): string => $one(
    'SELECT LOWER(HEX(initial_state_id)) FROM state_machine WHERE technical_name = :name',
    ['name' => $machine],
);

$own = $channel(Defaults::SALES_CHANNEL_TYPE_STOREFRONT);
$foreign = $channel(Defaults::SALES_CHANNEL_TYPE_API);
$taxId = $one('SELECT LOWER(HEX(id)) FROM tax ORDER BY position, tax_rate DESC LIMIT 1');
$salutationId = $one('SELECT LOWER(HEX(id)) FROM salutation LIMIT 1');
$countryId = $one('SELECT LOWER(HEX(country_id)) FROM sales_channel WHERE id = UNHEX(:id)', ['id' => $foreign]);
$customerGroupId = $one('SELECT LOWER(HEX(customer_group_id)) FROM sales_channel WHERE id = UNHEX(:id)', ['id' => $foreign]);
$paymentMethodId = $one('SELECT LOWER(HEX(payment_method_id)) FROM sales_channel WHERE id = UNHEX(:id)', ['id' => $foreign]);
$shippingMethodId = $one('SELECT LOWER(HEX(shipping_method_id)) FROM sales_channel WHERE id = UNHEX(:id)', ['id' => $foreign]);
$localeId = $one('SELECT LOWER(HEX(locale_id)) FROM language WHERE id = UNHEX(:id)', ['id' => Defaults::LANGUAGE_SYSTEM]);

// all or nothing
$connection->beginTransaction();

// The limited role and user
$roleId = Uuid::randomHex();
// read access to every entity: only the plugin stands between the user and other channels' records
$registry = $container->get(Shopware\Core\Framework\DataAbstractionLayer\DefinitionInstanceRegistry::class);
assert($registry instanceof Shopware\Core\Framework\DataAbstractionLayer\DefinitionInstanceRegistry);
$privileges = array_map(static fn (string $entity): string => $entity . ':read', array_keys($registry->getDefinitions()));
// and the endpoints the leak check probes, so the plugin (not a missing privilege) has to refuse them
$privileges = [...$privileges, 'api_proxy_imitate-customer', 'api_proxy_switch-customer'];
$repository('acl_role')->create([['id' => $roleId, 'name' => 'scope-ci', 'privileges' => $privileges]], $context);
$connection->insert('mn_acl_role_sales_channel', [
    'acl_role_id' => Uuid::fromHexToBytes($roleId),
    'sales_channel_id' => Uuid::fromHexToBytes($own),
]);
$password = bin2hex(random_bytes(12));
$repository('user')->create([[
    'username' => 'scope-ci',
    'firstName' => 'Scope',
    'lastName' => 'CI',
    'email' => 'scope-ci@example.com',
    'password' => $password,
    'localeId' => $localeId,
    'admin' => false,
    'aclRoles' => [['id' => $roleId]],
]], $context);

// Records of the foreign channel
$productId = Uuid::randomHex();
$repository('product')->create([[
    'id' => $productId,
    'productNumber' => 'SCOPE-CI-FOREIGN',
    'name' => 'Scope CI foreign product',
    'stock' => 10,
    'taxId' => $taxId,
    'price' => [['currencyId' => Defaults::CURRENCY, 'gross' => 10, 'net' => 8.4, 'linked' => true]],
    'visibilities' => [['salesChannelId' => $foreign, 'visibility' => 30]],
]], $context);

$customerId = Uuid::randomHex();
$addressId = Uuid::randomHex();
$repository('customer')->create([[
    'id' => $customerId,
    'customerNumber' => 'SCOPE-CI-1',
    'salesChannelId' => $foreign,
    'groupId' => $customerGroupId,
    'defaultPaymentMethodId' => $paymentMethodId,
    'defaultBillingAddressId' => $addressId,
    'defaultShippingAddressId' => $addressId,
    'salutationId' => $salutationId,
    'firstName' => 'Foreign',
    'lastName' => 'Customer',
    'email' => 'scope-ci-foreign@example.com',
    'password' => bin2hex(random_bytes(12)),
    'addresses' => [[
        'id' => $addressId,
        'salutationId' => $salutationId,
        'firstName' => 'Foreign',
        'lastName' => 'Customer',
        'street' => 'Street 1',
        'zipcode' => '12345',
        'city' => 'City',
        'countryId' => $countryId,
    ]],
]], $context);
$repository('customer_recovery')->create([['customerId' => $customerId, 'hash' => bin2hex(random_bytes(16))]], $context);
$repository('customer_wishlist')->create([[
    'customerId' => $customerId,
    'salesChannelId' => $foreign,
    'products' => [['productId' => $productId]],
]], $context);

$price = static fn (float $amount): CalculatedPrice => new CalculatedPrice($amount, $amount, new CalculatedTaxCollection(), new TaxRuleCollection());
$lineItemId = Uuid::randomHex();
$orderAddressId = Uuid::randomHex();
$orderId = Uuid::randomHex();
$repository('order')->create([[
    'id' => $orderId,
    'orderNumber' => 'SCOPE-CI-1',
    'salesChannelId' => $foreign,
    'currencyId' => Defaults::CURRENCY,
    'languageId' => Defaults::LANGUAGE_SYSTEM,
    'currencyFactor' => 1.0,
    'orderDateTime' => (new DateTimeImmutable())->format(Defaults::STORAGE_DATE_TIME_FORMAT),
    'stateId' => $initialState('order.state'),
    'price' => new CartPrice(10, 10, 10, new CalculatedTaxCollection(), new TaxRuleCollection(), CartPrice::TAX_STATE_GROSS),
    'shippingCosts' => $price(0),
    'itemRounding' => json_decode(json_encode(new CashRoundingConfig(2, 0.01, true), \JSON_THROW_ON_ERROR), true),
    'totalRounding' => json_decode(json_encode(new CashRoundingConfig(2, 0.01, true), \JSON_THROW_ON_ERROR), true),
    'billingAddressId' => $orderAddressId,
    'orderCustomer' => [
        'customerId' => $customerId,
        'email' => 'scope-ci-foreign@example.com',
        'firstName' => 'Foreign',
        'lastName' => 'Customer',
        'salutationId' => $salutationId,
    ],
    'addresses' => [[
        'id' => $orderAddressId,
        'salutationId' => $salutationId,
        'firstName' => 'Foreign',
        'lastName' => 'Customer',
        'street' => 'Street 1',
        'zipcode' => '12345',
        'city' => 'City',
        'countryId' => $countryId,
    ]],
    'lineItems' => [[
        'id' => $lineItemId,
        'identifier' => $productId,
        'referencedId' => $productId,
        'productId' => $productId,
        'type' => 'product',
        'label' => 'Scope CI foreign product',
        'quantity' => 1,
        'position' => 1,
        'price' => $price(10),
        'payload' => ['productNumber' => 'SCOPE-CI-FOREIGN'],
    ]],
    'deliveries' => [[
        'shippingOrderAddressId' => $orderAddressId,
        'shippingMethodId' => $shippingMethodId,
        'stateId' => $initialState('order_delivery.state'),
        'shippingDateEarliest' => (new DateTimeImmutable())->format(Defaults::STORAGE_DATE_TIME_FORMAT),
        'shippingDateLatest' => (new DateTimeImmutable('+3 days'))->format(Defaults::STORAGE_DATE_TIME_FORMAT),
        'shippingCosts' => $price(0),
        'positions' => [['orderLineItemId' => $lineItemId, 'price' => $price(10)]],
    ]],
    'transactions' => [[
        'paymentMethodId' => $paymentMethodId,
        'stateId' => $initialState('order_transaction.state'),
        'amount' => $price(10),
        'captures' => [[
            'stateId' => $initialState('order_transaction_capture.state'),
            'amount' => $price(10),
            'refunds' => [[
                'stateId' => $initialState('order_transaction_capture_refund.state'),
                'amount' => $price(5),
                'positions' => [['orderLineItemId' => $lineItemId, 'quantity' => 1, 'amount' => $price(5)]],
            ]],
        ]],
    ]],
]], $context);

// a state transition of the foreign order, with an internal comment
$repository('state_machine_history')->create([[
    'stateMachineId' => $one('SELECT LOWER(HEX(id)) FROM state_machine WHERE technical_name = :name', ['name' => 'order.state']),
    'entityName' => 'order',
    'referencedId' => $orderId,
    'referencedVersionId' => Defaults::LIVE_VERSION,
    'fromStateId' => $initialState('order.state'),
    'toStateId' => $initialState('order.state'),
    'transitionActionName' => 'process',
    'internalComment' => 'Scope CI internal comment',
]], $context);

$repository('newsletter_recipient')->create([[
    'email' => 'scope-ci-newsletter@example.com',
    'status' => 'direct',
    'hash' => bin2hex(random_bytes(16)),
    'salesChannelId' => $foreign,
    'languageId' => Defaults::LANGUAGE_SYSTEM,
]], $context);
$repository('promotion')->create([[
    'name' => 'Scope CI foreign promotion',
    'active' => true,
    'salesChannels' => [['salesChannelId' => $foreign, 'priority' => 1]],
]], $context);

$connection->commit();

echo "SCOPE_USER=scope-ci\n";
echo "SCOPE_PASSWORD=$password\n";
echo "SCOPE_FOREIGN_CHANNEL=$foreign\n";
echo "SCOPE_FOREIGN_ORDER=$orderId\n";
echo "SCOPE_FOREIGN_CUSTOMER=$customerId\n";
echo "SCOPE_FOREIGN_PRODUCT=$productId\n";
