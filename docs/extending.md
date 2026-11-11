# Extending

Other plugins can limit their own entities, add permission presets, open per-channel settings pages, and use the scope in their administration modules. Everything below works through service tags and a public administration service; no class of this plugin needs to be replaced.

- [Public API](#public-api)
- [Read and write rules](#read-and-write-rules)
- [Presets](#presets)
- [Settings pages](#settings-pages)
- [Administration](#administration)
- [Plain SQL and other special endpoints](#plain-sql-and-other-special-endpoints)
- [Where the channels come from](#where-the-channels-come-from)

## Public API

Stable for extensions (changes are noted in the changelog):

| What | Use |
|---|---|
| `Mn\SalesChannelScope\Tags` | the service tags below |
| `Rule\ReadRule`, `Rule\WriteRule`, `Rule\WriteScope` | your own read and write rules |
| `Rule\SalesChannelColumnRule`, `Rule\ChannelColumnLookup` | the common case: an entity with a `sales_channel_id` column |
| `Preset\PresetDirectory` | your own presets |
| `Scope\SettingsDomains` | your own per-channel settings |
| `Scope\ChannelScope::salesChannelIds()` | the current user's channels in your own services |
| `Scope\RoleChannelLoader` | where a user's channels come from (decorate it) |
| `Exception\ChannelScopeException` | refusals (`MN_CHANNEL_SCOPE__WRITE_DENIED`, `MN_CHANNEL_SCOPE__SETTINGS_DENIED`, `MN_CHANNEL_SCOPE__ROUTE_DENIED`) |
| `Shopware.Service('mnChannelScope')` | the administration API |

Everything else (the built-in guards, lookups, `ScopeRules`) is internal and may change.

The plugin only limits users whose every role has sales channels (see [architecture](architecture.md#who-is-limited)). Your rules are only called for those users; for everyone else nothing changes.

## Read and write rules

### An entity with a sales channel column

Most entities that belong to a sales channel have a `sales_channel_id` column. `SalesChannelColumnRule` covers them completely: limited users see only records of their channels, change only those, and can't move records to other channels. Register one service per entity, tagged as both a read and a write rule:

```php
// src/Resources/config/services.php of your plugin
use Mn\SalesChannelScope\Rule\ChannelColumnLookup;
use Mn\SalesChannelScope\Rule\SalesChannelColumnRule;
use Mn\SalesChannelScope\Tags;

$services->set('acme.scope.store_notice', SalesChannelColumnRule::class)
    ->args(['acme_store_notice', service(ChannelColumnLookup::class)])
    ->tag(Tags::READ_RULE)
    ->tag(Tags::WRITE_RULE);
```

Or in XML:

```xml
<service id="acme.scope.store_notice" class="Mn\SalesChannelScope\Rule\SalesChannelColumnRule">
    <argument>acme_store_notice</argument>
    <argument type="service" id="Mn\SalesChannelScope\Rule\ChannelColumnLookup"/>
    <tag name="mn_channel_scope.read_rule"/>
    <tag name="mn_channel_scope.write_rule"/>
</service>
```

Further arguments: the field name (default `salesChannelId`), the column (default `sales_channel_id`), and `allowAllChannels` (default `false`): when `true`, records without a channel apply to all channels and are visible, but only unrestricted users change them.

The plugin itself registers newsletter recipients this way (`mn_channel_scope.rule.newsletter_recipient`).

### Your own read rule

A read rule returns a DAL filter for the entities it knows, and `null` for all others. All rules' filters for an entity apply together (AND), so a rule can only narrow what a user sees.

```php
use Mn\SalesChannelScope\Rule\ReadRule;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Filter\EqualsAnyFilter;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Filter\Filter;

/** Order notes belong to their order's sales channel. */
final class OrderNoteReadRule implements ReadRule
{
    public function readFilter(string $entity, array $salesChannelIds): ?Filter
    {
        return $entity === 'acme_order_note'
            ? new EqualsAnyFilter('order.salesChannelId', $salesChannelIds)
            : null;
    }
}
```

```php
$services->set(OrderNoteReadRule::class)->tag(Tags::READ_RULE);
```

The filter is added to every search and aggregation of a limited user, and to every to-many association of your entity loaded with another entity (an order's notes). Keep it cheap: it is part of every query.

### Your own write rule

A write rule sees each command of a limited user's write before it is executed, in storage format (column names, ids as bytes). Return a reason to refuse it, `null` to allow it. `WriteScope` knows the user's channels and the whole write, so records inserted together can be accepted.

```php
use Doctrine\DBAL\Connection;
use Mn\SalesChannelScope\Rule\WriteRule;
use Mn\SalesChannelScope\Rule\WriteScope;
use Shopware\Core\Framework\DataAbstractionLayer\Write\Command\InsertCommand;
use Shopware\Core\Framework\DataAbstractionLayer\Write\Command\WriteCommand;

final class OrderNoteWriteRule implements WriteRule
{
    public function __construct(private readonly Connection $connection)
    {
    }

    public function deny(WriteCommand $command, WriteScope $scope): ?string
    {
        if ($command->getEntityName() !== 'acme_order_note') {
            return null;
        }

        // the order the note belongs to now (stored) and afterwards (payload)
        $orderId = $command->getPayload()['order_id']
            ?? ($command instanceof InsertCommand ? null : $this->storedOrderId($command->getPrimaryKey()['id']));

        if ($orderId === null || !$scope->isOwn($this->channelOfOrder($orderId))) {
            return 'its order belongs to another sales channel';
        }

        return null;
    }

    private function storedOrderId(string $noteId): ?string
    {
        $value = $this->connection->fetchOne('SELECT order_id FROM acme_order_note WHERE id = :id', ['id' => $noteId]);

        return \is_string($value) ? $value : null;
    }

    private function channelOfOrder(string $orderId): ?string
    {
        $value = $this->connection->fetchOne(
            'SELECT LOWER(HEX(sales_channel_id)) FROM `order` WHERE id = :id AND version_id = :version',
            ['id' => $orderId, 'version' => \Shopware\Core\Framework\Uuid\Uuid::fromHexToBytes(\Shopware\Core\Defaults::LIVE_VERSION)],
        );

        return \is_string($value) ? $value : null;
    }
}
```

```php
$services->set(OrderNoteWriteRule::class)
    ->args([service(Connection::class)])
    ->tag(Tags::WRITE_RULE);
```

Check both sides of an update: the stored record (it must be the user's now) and the payload (it must stay the user's). Use `$scope->insertOf($entity, $id)` to accept children of a record inserted in the same write. A refused command fails the whole write with `MN_CHANNEL_SCOPE__WRITE_DENIED` and your reason.

### Rules for shared records

When a record can belong to several channels (like products), the plugin's convention is: visible when it belongs to one of the user's channels, changeable only when it belongs to the user's channels only (`$scope->ownsAll($channels)`). Shared records are read-only, because a change would affect channels the user is not responsible for.

## Presets

Add a directory with preset files (format in [presets](presets.md#preset-files)):

```php
use Mn\SalesChannelScope\Preset\PresetDirectory;
use Mn\SalesChannelScope\Tags;

$services->set('acme.scope.presets', PresetDirectory::class)
    ->args([__DIR__ . '/../channel-scope-presets'])
    ->tag(Tags::PRESET_DIRECTORY);
```

The presets appear in the role page's *Permission presets* card (ordered by `position`) and in `mn:channel-scope:preset`. A name that already exists in a directory with a higher tag priority is ignored; the plugin's own directory has priority 100.

## Settings pages

To let limited users change your plugin's per-channel configuration, register its domain on the server and its page in the administration.

Server (which configuration keys limited users read and change, for their own channels only):

```php
use Mn\SalesChannelScope\Scope\SettingsDomains;
use Mn\SalesChannelScope\Tags;

$services->set('acme.scope.settings', SettingsDomains::class)
    ->args([
        ['AcmeShipping.config'],                  // editable (and readable)
        [],                                       // readable only
        ['AcmeShipping.config.apiSecret'],        // never readable
    ])
    ->tag(Tags::SETTINGS_DOMAINS);
```

A domain matches the key itself and everything below it. Settings for all channels stay with unrestricted users.

Administration (the page in the settings overview and its route, which otherwise redirects limited users to the dashboard):

```js
Shopware.Service('mnChannelScope').addSettingsRoutePrefix('sw.settings.acme.shipping');
```

The page also needs the permissions it uses on the role, for example through a preset.

## Administration

The plugin registers `Shopware.Service('mnChannelScope')`:

| Method | Returns |
|---|---|
| `load()` | `Promise<{ restricted, salesChannelIds, categoryEntryPoints: { own, foreign } }>`, cached per login |
| `loadCmsPage(id)` | `Promise<{ restricted, editable, salesChannelIds }>` of a Shopping Experience layout |
| `loadPresets()` | `Promise<preset[]>` as on the role page |
| `registerReadOnlyGuard(isReadOnly, privileges)` | while `isReadOnly()` is true, `acl.can()` answers false for the privileges; returns the function that removes the guard |
| `canEditCategory(scope, category)` | whether a limited user may edit a category |
| `foreignChannelIds(scope, product, parent)` | sales channels of a product that are not the user's |
| `addSettingsRoutePrefix(prefix)`, `settingsRoutePrefixes()`, `isSettingsRoute(route)`, `isAllowedSettingsRoute(route)` | settings pages limited users may open |

Example: a detail page that shows shared records read-only, the way the product page does:

```js
Shopware.Component.override('acme-store-notice-detail', {
    data() {
        return { mnScope: null };
    },

    computed: {
        isSharedNotice() {
            const channels = this.notice?.salesChannelIds ?? [];

            return !!this.mnScope?.restricted
                && channels.some((id) => !this.mnScope.salesChannelIds.includes(id));
        },
    },

    created() {
        const scope = Shopware.Service('mnChannelScope');
        this.removeGuard = scope.registerReadOnlyGuard(() => this.isSharedNotice, ['acme_notice.editor', 'acme_notice.deleter']);
        scope.load().then((result) => { this.mnScope = result; });
    },

    beforeUnmount() {
        this.removeGuard?.();
    },
});
```

The administration only helps the user. Always enforce the rule on the server with a read and a write rule.

## Plain SQL and other special endpoints

The rules only see what goes through Shopware's data layer. If your plugin has an endpoint that reads or writes with plain SQL, or works in the system scope, ask `ChannelScope` yourself:

```php
use Mn\SalesChannelScope\Exception\ChannelScopeException;
use Mn\SalesChannelScope\Scope\ChannelScope;

public function report(Context $context): JsonResponse
{
    $channels = $this->scope->salesChannelIds($context); // null: unrestricted

    $sql = 'SELECT … FROM `order` WHERE …';
    if ($channels !== null) {
        $sql .= ' AND sales_channel_id IN (:channels)'; // bind Uuid::fromHexToBytesList($channels)
    }
    // …
}
```

To refuse a request, throw `ChannelScopeException::writeDenied($entity, $reason)`. The plugin's own route guards (`SystemConfigGuardSubscriber`, `CloneGuardSubscriber`, `ThemeGuardSubscriber`) are examples.

## Where the channels come from

By default, a user's channels are the channels of their roles (role page, table `mn_acl_role_sales_channel`). To take them from somewhere else (an SSO group, a custom field), decorate `RoleChannelLoader`:

```php
use Mn\SalesChannelScope\Scope\RoleChannelLoader;

final class SsoChannelLoader implements RoleChannelLoader
{
    public function __construct(private readonly RoleChannelLoader $inner)
    {
    }

    /**
     * @return array<string, list<string>> role id => sales channel ids; an empty list means unrestricted
     */
    public function load(string $userId): array
    {
        $roles = $this->inner->load($userId);
        // add, remove or replace channels per role
        return $roles;
    }
}
```

```php
$services->set(SsoChannelLoader::class)
    ->decorate(RoleChannelLoader::class)
    ->args([service('.inner')]);
```

A user is limited when every role has channels; an empty list for any role makes them unrestricted.
