# Testing

| Suite | What | Needs |
|---|---|---|
| `tests/Unit` | PHPUnit: rules, guards, lookups' callers, presets, controller | Shopware's classes (no database, no kernel) |
| `tests/js` | node's test runner: the administration services | node 20+ |
| PHPStan | level 8 on `src` and `tests` | Shopware's classes |
| [Acceptance checklist](#acceptance-checklist) | the whole plugin in a shop with two sales channels | a shop |

## Running the tests

### Standalone (as in CI)

```bash
composer install       # Shopware core and administration, PHPUnit, PHPStan
composer test          # PHPUnit
composer phpstan       # PHPStan
composer test:js       # node --experimental-detect-module --test tests/js/
```

Without Shopware Commercial, the tests that need it (review summaries) are skipped.

### Inside a shop

When the plugin lives in `custom/plugins/MnSalesChannelScope`, the tests use the shop's classes, including Commercial:

```bash
cd custom/plugins/MnSalesChannelScope
php /path/to/phpunit.phar --testsuite unit
```

or point to any shop with `SHOPWARE_DIR=/path/to/shop`. The bootstrap uses `SHOPWARE_DIR`, then the shop the plugin lives in, then the plugin's own `vendor`.

## Continuous integration

`.github/workflows/tests.yml` runs on every push and pull request:

- PHPStan and PHPUnit on PHP 8.2, 8.3 and 8.4;
- the administration tests on node 20;
- a JSON check of the preset files.

## Writing tests

The unit tests replace the database with small test doubles in `tests/`:

| Double | Stands for |
|---|---|
| `Ids` | two sales channels (`DE`, `EN`), a user limited to `DE` (`DE_USER`) and an unrestricted user (`FREE_USER`); `Ids::scope()`, `Ids::context()` |
| `StaticRoleChannelLoader` | roles and their channels |
| `StaticScopeLookup` | stored orders, customers, products, reviews, settings, landing pages, … by hex id |
| `StaticCategoryTreeSource` | a category tree with entries of both channels, a shared tree and an orphan |
| `StaticCmsUsageSource` | layouts used by one channel, both, none, and a locked one |
| `StaticPromotionSource` | promotions of one channel, both, and the other |

Write commands are built with Shopware's `StaticDefinitionInstanceRegistry` and passed to the guard in a `PreWriteValidationEvent`; read rules are called directly or through `ReadFilterSubscriber`. Every guard test also checks that unrestricted users are not affected.

For a new rule, test at least:

1. records of the user's channels: visible and changeable;
2. records of other channels: hidden and refused (update, delete);
3. moving a record to another channel, and creating one there: refused;
4. records inserted together in one write: accepted;
5. unrestricted users: never refused.

## Acceptance checklist

The unit tests can't cover the administration screens and the interplay with Shopware's own checks. Before a release, check in a shop with two sales channels (A and B), a role limited to A with the presets, and a user with that role (not an administrator):

**Reading**

- [ ] Orders, customers, products, categories, layouts, promotions, reviews: only those of A in lists, search and dashboard.
- [ ] An order, customer, product of B opened by URL: not found.
- [ ] The *Sales Channels* menu (with the settings preset): only A.
- [ ] Settings overview: only the preset's pages; other settings pages by URL: redirect to the dashboard.

**Changing**

- [ ] An order, customer, product, category, layout, promotion of A: saves.
- [ ] Records of B through the API (`PATCH /api/order/{id}`): refused with `MN_CHANNEL_SCOPE__WRITE_DENIED`.
- [ ] A product sold in A and B: read-only hint, fields disabled, save refused.
- [ ] A new product, promotion, layout: starts with A, saves, stays visible.
- [ ] Moving a record to B (`salesChannelId`, visibilities, promotion assignment): refused.
- [ ] Cart settings of A: saves; of all channels and of B: refused (`MN_CHANNEL_SCOPE__SETTINGS_DENIED`).

**Special endpoints**

- [ ] *Log in as customer* for a customer of B: refused.
- [ ] Clone of a layout or order of B (`POST /api/_action/clone/...`): refused; a copy of a shared layout belongs to A.
- [ ] Assigning a theme to B: refused.
- [ ] Global configuration values with credentials (`core.mailerSettings`, `core.store`): not readable.
- [ ] With admin OpenSearch (`SHOPWARE_ADMIN_ES_ENABLED=1`): the global search finds the user's records and none of B; `POST /api/_admin/es-search` is refused (`MN_CHANNEL_SCOPE__ROUTE_DENIED`).

**Unrestricted users**

- [ ] An administrator and a user with a role without sales channels see and change everything, as without the plugin.
