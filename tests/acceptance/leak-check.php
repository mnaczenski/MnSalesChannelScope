<?php declare(strict_types=1);

/**
 * Leak check: logs in as a limited admin user and looks for records of
 * another sales channel through the Admin API. Needs nothing but PHP with
 * curl and a running shop with the plugin installed.
 *
 *   SCOPE_SHOP_URL=https://shop.example \
 *   SCOPE_USER=team-a SCOPE_PASSWORD=... \
 *   SCOPE_FOREIGN_CHANNEL=<id of a sales channel the user must not see> \
 *   php tests/acceptance/leak-check.php
 *
 * Optional: SCOPE_FOREIGN_ORDER, SCOPE_FOREIGN_CUSTOMER, SCOPE_FOREIGN_PRODUCT
 * (records only of the foreign channel) for the direct-access and route checks.
 *
 * 1. Every entity of the entity schema with a path to a sales channel, a
 *    customer or a product is searched for records of the foreign channel;
 *    any hit is a leak (unless listed in ACCEPTED with the reason).
 * 2. Endpoints that take a sales channel or customer id refuse foreign ones.
 * 3. Foreign records can't be opened or changed by id.
 *
 * Exit code 1 on a leak. The check only reads; writes are covered by the
 * unit tests of the write rules.
 */

const ACCEPTED = [
    // visibilities of products shared with the foreign channel: the product is readable (not changeable), so are its visibilities
    'product_visibility' => 'visibilities of shared products',
    // a product's main category per channel, read-only like the product
    'main_category' => 'main categories of shared products',
];

// Associations the search does not follow: global records, or ones the plugin limits differently (category trees, layouts)
const NOT_FOLLOWED = ['language', 'currency', 'user', 'integration', 'media', 'state_machine_state', 'country', 'salutation', 'rule', 'payment_method', 'shipping_method', 'tax', 'version', 'category', 'cms_page', 'theme', 'locale', 'unit', 'delivery_time', 'customer_group', 'product_manufacturer', 'property_group_option', 'app', 'acl_role'];

$url = rtrim(env('SCOPE_SHOP_URL'), '/');
$foreign = strtolower(env('SCOPE_FOREIGN_CHANNEL'));
$token = login($url, env('SCOPE_USER'), env('SCOPE_PASSWORD'));

[$status, $me] = call($url, 'GET', '/api/_action/mn-channel-scope/me', $token);
if ($status !== 200 || !($me['restricted'] ?? false)) {
    fail('the user is not limited to sales channels (is the plugin active, the user no administrator, the role limited?)');
}
$own = $me['salesChannelIds'];
if (in_array($foreign, $own, true)) {
    fail('SCOPE_FOREIGN_CHANNEL is one of the user\'s own channels');
}
printf("User limited to %s; looking for records of %s\n\n", implode(', ', $own), $foreign);

$leaks = 0;
$report = static function (bool $ok, string $line) use (&$leaks): void {
    $leaks += $ok ? 0 : 1;
    printf("  %s %s\n", $ok ? 'ok  ' : 'LEAK', $line);
};

// 1. Entity sweep
[, $schema] = call($url, 'GET', '/api/_info/entity-schema.json', $token);
$checked = $forbidden = $global = $unsearchable = 0;
echo "Entities\n";
foreach (array_keys($schema) as $entity) {
    $filters = foreignFilters($schema, $entity, $foreign, $own);
    if ($filters === []) {
        ++$global;
        continue;
    }
    foreach ($filters as $path => $filter) {
        [$status, $result] = call($url, 'POST', '/api/search/' . str_replace('_', '-', $entity), $token, [
            'limit' => 1, 'total-count-mode' => 1, 'filter' => [$filter], 'includes' => [$entity => ['id']],
        ]);
        if ($status === 403) {
            ++$forbidden;
            break; // no privilege: nothing to read
        }
        ++$checked;
        $total = $result['total'] ?? null;
        if (in_array($result['errors'][0]['code'] ?? '', ['FRAMEWORK__MAPPING_ENTITY_DEFINITION_CLASSES', 'FRAMEWORK__ACCESSOR_NOT_ALLOWED'], true)) {
            ++$unsearchable; // mapping tables and paths the API can't filter by: not readable this way either
        } elseif ($status !== 200) {
            printf("  ?    %-45s via %s: HTTP %d %s\n", $entity, $path, $status, errorOf($result));
        } elseif ($total > 0) {
            isset(ACCEPTED[$entity])
                ? printf("  note %-45s via %s: %d (%s)\n", $entity, $path, $total, ACCEPTED[$entity])
                : $report(false, sprintf('%-45s via %s: %d records', $entity, $path, $total));
        }
    }
}
printf("  %d paths checked (%d not searchable), %d entities without privilege, %d entities without a sales channel\n\n", $checked, $unsearchable, $forbidden, $global);

// 2. Endpoints with a sales channel or customer id
echo "Endpoints\n";
$probes = [
    ['POST', "/api/_admin/product-stream-preview/$foreign", ['limit' => 1]],
    ['GET', "/api/_action/sales-channel-file/robots/$foreign", null],
    ['POST', "/api/_action/sales-channel-file/robots/$foreign/preview", ['fileName' => 'robots.txt']],
    ['GET', "/api/_action/preview-search/search?salesChannelId=$foreign&entity=product&term=a", null],
    ['POST', '/api/_proxy/generate-imitate-customer-token', ['salesChannelId' => $foreign, 'customerId' => getenv('SCOPE_FOREIGN_CUSTOMER') ?: str_repeat('0', 32)]],
];
if ($customer = getenv('SCOPE_FOREIGN_CUSTOMER')) {
    $probes[] = ['PATCH', '/api/_proxy/switch-customer', ['salesChannelId' => $own[0], 'customerId' => $customer]];
}
foreach ($probes as [$method, $path, $body]) {
    [$status, $result] = call($url, $method, $path, $token, $body);
    // refused by the plugin, by a missing privilege, or not found (Commercial not installed)
    $refused = in_array($status, [403, 404], true) || str_contains(errorOf($result), 'one of yours');
    $report($refused, sprintf('%-6s %s: HTTP %d %s', $method, $path, $status, errorOf($result)));
}

// 3. Foreign records by id
echo "\nRecords by id\n";
foreach (['order' => 'SCOPE_FOREIGN_ORDER', 'customer' => 'SCOPE_FOREIGN_CUSTOMER', 'product' => 'SCOPE_FOREIGN_PRODUCT'] as $entity => $variable) {
    $id = getenv($variable);
    if (!$id) {
        printf("  skip %s (set %s)\n", $entity, $variable);
        continue;
    }
    $path = '/api/' . $entity . '/' . $id;
    [$status] = call($url, 'GET', $path, $token);
    $report($status === 404 || $status === 403, "GET    $path: HTTP $status");
}
if ($order = getenv('SCOPE_FOREIGN_ORDER')) {
    // state transitions point to their order by id only (no association the entity sweep could follow)
    [$status, $result] = call($url, 'POST', '/api/search/state-machine-history', $token, [
        'total-count-mode' => 1, 'filter' => [['type' => 'equals', 'field' => 'referencedId', 'value' => $order]],
    ]);
    $report($status === 403 || ($result['total'] ?? 1) === 0, sprintf('POST   /api/search/state-machine-history (referencedId %s): HTTP %d, %s transitions', $order, $status, $result['total'] ?? '?'));
}
[$status] = call($url, 'GET', "/api/sales-channel/$foreign", $token);
$report($status === 404 || $status === 403, "GET    /api/sales-channel/$foreign: HTTP $status");

echo $leaks === 0 ? "\nNo leaks found.\n" : "\n$leaks leak(s) found.\n";
exit($leaks === 0 ? 0 : 1);

/**
 * Filters that match records of the foreign channel, by path, following
 * many-to-one associations (four steps at most) to a sales channel, a
 * customer or a product.
 *
 * @return array<string, array<string, mixed>>
 */
function foreignFilters(array $schema, string $entity, string $foreign, array $own): array
{
    $filters = [];
    $queue = [[$entity, '']];
    $seen = [$entity => true];
    while ($queue !== []) {
        [$current, $prefix] = array_shift($queue);
        if ($prefix === '') {
            // the entity itself
            $self = rootFilter($current, '', $foreign, $own);
            if ($self !== null) {
                return [$current => $self];
            }
        }
        if (substr_count($prefix, '.') >= 4) {
            continue;
        }
        foreach ($schema[$current]['properties'] ?? [] as $name => $property) {
            if (!in_array($property['relation'] ?? null, ['many_to_one', 'one_to_one'], true) || !isset($property['entity'])) {
                continue;
            }
            $path = $prefix . $name . '.';
            $root = rootFilter($property['entity'], $path, $foreign, $own);
            if ($root !== null) {
                $filters[$path] = $root;
                continue;
            }
            if (!isset($seen[$property['entity']]) && !in_array($property['entity'], NOT_FOLLOWED, true)) {
                $seen[$property['entity']] = true;
                $queue[] = [$property['entity'], $path];
            }
        }
    }

    return array_slice($filters, 0, 2, true);
}

/**
 * Records of the foreign channel the user must not see, for the records
 * that define ownership.
 */
function rootFilter(string $entity, string $prefix, string $foreign, array $own): ?array
{
    $not = static fn (array ...$queries) => ['type' => 'not', 'operator' => 'or', 'queries' => $queries];
    $any = static fn (string $field, array $value) => ['type' => 'equalsAny', 'field' => $prefix . $field, 'value' => $value];

    return match ($entity) {
        'sales_channel' => ['type' => 'equals', 'field' => $prefix . 'id', 'value' => $foreign],
        // registered in the foreign channel, neither bound to nor ordered in an own one
        'customer' => ['type' => 'multi', 'operator' => 'and', 'queries' => [
            ['type' => 'equals', 'field' => $prefix . 'salesChannelId', 'value' => $foreign],
            $not($any('boundSalesChannelId', $own), $any('orderCustomers.order.salesChannelId', $own)),
        ]],
        // sold in the foreign channel, not in an own one
        'product' => ['type' => 'multi', 'operator' => 'and', 'queries' => [
            $any('visibilities.salesChannelId', [$foreign]),
            $not($any('visibilities.salesChannelId', $own), $any('parent.visibilities.salesChannelId', $own)),
        ]],
        default => null,
    };
}

function login(string $url, string $user, string $password): string
{
    [$status, $result] = call($url, 'POST', '/api/oauth/token', null, [
        'grant_type' => 'password', 'client_id' => 'administration', 'scopes' => 'write', 'username' => $user, 'password' => $password,
    ]);
    if ($status !== 200 || !isset($result['access_token'])) {
        fail("login as $user failed: HTTP $status " . errorOf($result));
    }

    return $result['access_token'];
}

/**
 * @return array{int, mixed}
 */
function call(string $url, string $method, string $path, ?string $token, ?array $body = null): array
{
    $curl = curl_init($url . $path);
    curl_setopt_array($curl, [
        CURLOPT_CUSTOMREQUEST => $method,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT => 60,
        CURLOPT_HTTPHEADER => array_filter(['Content-Type: application/json', 'Accept: application/json', $token ? 'Authorization: Bearer ' . $token : null]),
        CURLOPT_POSTFIELDS => $body === null ? null : json_encode($body),
    ]);
    $raw = (string) curl_exec($curl);
    $status = (int) curl_getinfo($curl, CURLINFO_RESPONSE_CODE);

    return [$status, json_decode($raw, true)];
}

function errorOf(mixed $result): string
{
    $error = is_array($result) ? ($result['errors'][0] ?? null) : null;

    return $error === null ? '' : trim(($error['code'] ?? '') . ' ' . substr((string) ($error['detail'] ?? ''), 0, 80));
}

function env(string $name): string
{
    $value = getenv($name);
    if (!is_string($value) || $value === '') {
        fail("set $name");
    }

    return $value;
}

function fail(string $message): never
{
    fwrite(STDERR, $message . "\n");
    exit(2);
}
