# Module: tests-ci (Tests, CI & leak check)
_Path: tests/ (bootstrap.php, Ids.php, Static*.php, Unit/, js/, acceptance/leak-check.php, ci/, fixtures/), .github/workflows/tests.yml, phpunit.xml.dist, phpstan.neon.dist, composer.json scripts, docs/testing.md_

Evidence note: no command was run (no local PHP/Shopware; `vendor/` is absent). Every command below is taken from `composer.json`, `.github/workflows/tests.yml`, `docs/testing.md` or the script headers. "[verified]" means seen in those files, not executed. Git works in this repo (history 2025-era plugin, 0.x releases between 2026-11-10 and 2026-11-13).

## Responsibility
Holds everything that proves the plugin's scope rules: PHPUnit unit tests with hand-written doubles (no DB, no kernel), node tests for the administration services, PHPStan level 8, and a CI pipeline that also installs real Shopware shops. The pipeline adds an acceptance "leak check" (a limited admin user probing the Admin API for another sales channel's records) and a check that the administration internals the plugin overrides still exist. [verified: docs/testing.md, .github/workflows/tests.yml]

## Entry points and interfaces

### Suites: commands, needs

| Suite | Command | Needs | Source |
|---|---|---|---|
| PHP unit | `composer test` = `phpunit --testsuite unit` | Shopware classes only: `SHOPWARE_DIR`, or the shop the plugin sits in (`dirname(__DIR__, 4)`), or plugin `vendor/` after `composer install` (needs `src/Core` or `vendor/shopware/core`). No DB, no kernel. | composer.json scripts; tests/bootstrap.php; phpunit.xml.dist [verified] |
| JS | `composer test:js` = `node --experimental-detect-module --test tests/js/` | Node 20 (CI). No npm install; Shopware global stubbed in tests/js/shopware-stub.mjs | composer.json; tests.yml job `js` [verified] |
| PHPStan | `composer phpstan` = `phpstan analyse --memory-limit=2G` (CI adds `-- --no-progress`) | Shopware classes (bootstrapFiles tests/bootstrap.php). Level 8, paths `src` and `tests`, excludes `src/Resources` and `tests/acceptance` | phpstan.neon.dist [verified] |
| Admin override check | `php custom/plugins/MnSalesChannelScope/tests/ci/check-admin-overrides.php <administration src dir>` | An installed shop or the Shopware repo; arg is `vendor/shopware/administration/Resources/app/administration/src` (shop) or `src/Administration/Resources/app/administration/src` (repo). Exit 0/1, 2 if dir missing | tests/ci/check-admin-overrides.php header; tests.yml [verified] |
| Seed | `php tests/ci/seed.php /path/to/shopware >> "$GITHUB_ENV"` then `bin/console mn:channel-scope:preset <preset> scope-ci` for orders, products, categories, marketing, cms, settings | Fresh installed shop with a Storefront-type and an API-type (Headless) sales channel, DB access; boots the kernel (`APP_ENV` default `prod`) | tests/ci/seed.php; tests.yml [verified] |
| Leak check | `SCOPE_SHOP_URL=... SCOPE_USER=... SCOPE_PASSWORD=... SCOPE_FOREIGN_CHANNEL=... [SCOPE_FOREIGN_ORDER/CUSTOMER/PRODUCT=...] php tests/acceptance/leak-check.php` | A running shop with the plugin active, PHP curl, a non-admin limited user. Exit 0 clean, 1 leak, 2 setup/login failure | tests/acceptance/leak-check.php [verified] |
| Preset JSON lint | `for f in src/Resources/config/presets/*.json; do python3 -m json.tool "$f" > /dev/null \|\| exit 1; done` | python3 | tests.yml job `presets` [verified] |
| Acceptance checklist | manual, in a two-channel shop | a shop and a human | docs/testing.md [verified] |

PHPUnit config: bootstrap `tests/bootstrap.php`, `failOnRisky` and `failOnWarning` on, one testsuite `unit` = `tests/Unit`, coverage source `src`. [verified: phpunit.xml.dist]
Dev deps: `phpstan/phpstan ^2.1`, `phpunit/phpunit ^11.5`; plugin requires `shopware/core` and `shopware/administration` `~6.7.14`, PHP `>=8.2`. [verified: composer.json]
`SHOPWARE_DIR` is read only by tests/bootstrap.php, not by seed or leak check. [verified]

### What the leak check verifies
Script: tests/acceptance/leak-check.php. Read-only (only POST used is search/token/probes; no writes). [verified]

Acting user: password-grant login via `/api/oauth/token` (client `administration`) as `SCOPE_USER`. It first calls `GET /api/_action/mn-channel-scope/me` and aborts (exit 2) if the user is not `restricted`, or if `SCOPE_FOREIGN_CHANNEL` is one of the user's own channels. In CI the user is `scope-ci`, a non-admin whose role is limited to the Storefront channel and holds `<entity>:read` for every registered DAL definition plus `api_proxy_imitate-customer` and `api_proxy_switch-customer`, so only the plugin, not missing privileges, can refuse. [verified: tests/ci/seed.php]

1. Entity sweep. Reads `/api/_info/entity-schema.json`; for each entity it builds filters that follow many-to-one/one-to-one associations (max 4 steps, `NOT_FOLLOWED` list skips global entities such as language, currency, user, media, category, cms_page, theme, rule, payment/shipping method, etc.) to a `sales_channel` (id = foreign), a `customer` (foreign `salesChannelId`, not bound to or ordered in an own channel) or a `product` (visible in foreign channel, not in an own one nor its parent's). At most 2 paths per entity. It POSTs `/api/search/<entity>` with that filter; `total > 0` is a LEAK, except `product_visibility` and `main_category` (`ACCEPTED`, printed as notes). HTTP 403 is counted as no privilege; HTTP 5xx is a failure (the read filter must not break a search), except for the mapping-definition error code. Non-200 other statuses print `?` without failing. [verified]
2. Plain search of each entity (and `<entity>_translation` when the entity has `translations`) with no foreign filter, to catch 5xx caused by the plugin's own filter; translations also searched through the parent record. [verified]
3. Endpoints, must be 403/404 or contain "one of yours": `POST /api/_admin/product-stream-preview/{foreign}`, `GET /api/_action/sales-channel-file/robots/{foreign}` and `.../preview`, `GET /api/_action/preview-search/search?salesChannelId={foreign}`, `POST /api/_proxy/generate-imitate-customer-token`, and, if a foreign customer is set, `PATCH /api/_proxy/switch-customer`. [verified]
4. Records by id (only if env var set, otherwise "skip"): `GET /api/order|customer|product/{id}` must be 404/403; `POST /api/search/state-machine-history` filtered on `referencedId` = foreign order must yield 0 (or 403); `GET /api/sales-channel/{foreign}` must be 404/403. [verified]

Not covered by the leak check: writes (left to unit tests), categories/CMS pages/themes (in `NOT_FOLLOWED`, "limited differently"), non-Admin-API surfaces, the Storefront, and admin-ES search. [verified: script comments; the "not covered" list itself is [inferred]]

Seeded foreign (Headless) data: product with visibility, customer with address, recovery, wishlist; order with line item, delivery, position, transaction, capture, refund, refund position; state_machine_history with internal comment; newsletter_recipient; promotion. [verified: tests/ci/seed.php] Seed runs in one DB transaction and prints `SCOPE_USER`, `SCOPE_PASSWORD`, `SCOPE_FOREIGN_CHANNEL/ORDER/CUSTOMER/PRODUCT` as KEY=value lines. [verified]

## Data model
No tables of its own. The seed script writes into a throwaway shop: `acl_role` (name `scope-ci`), the plugin table `mn_acl_role_sales_channel` via raw `$connection->insert`, `user`, product, customer and related entities listed above. [verified: tests/ci/seed.php] Migrations belong to another module (src/Migration/*) and are not exercised by any test here [inferred from grep: no test references them; migrations run only when the CI shop installs the plugin].

Fixtures: tests/fixtures/presets-extra/{orders,acme-newsletter}.json (valid extra presets) and tests/fixtures/presets-invalid/broken.json, used by tests/Unit/Preset/PermissionPresetsTest.php. [verified]
Overrides manifest: tests/ci/admin-overrides.json lists 14 administration components with `members` (methods/computed) and `blocks` (Twig). The 14 keys match the 14 directories in src/Resources/app/administration/src/extension. [verified]
How the override check works: finds directories named as the component under the admin src, concatenates their `*.js|ts` and `*.twig`, regex-matches `member(` / `member:` and `{% block name %}`. Textual regex, not an AST. [verified]

## Dependencies
- Uses: Shopware core/administration classes (`StaticDefinitionInstanceRegistry`, `PreWriteValidationEvent`, `AdminApiSource`, DAL), PHPUnit 11.5, PHPStan 2.1, node 20, `shopware/github-actions/setup-extension@main`, `actions/checkout@v4`, `shivammathur/setup-php@v2`, `actions/setup-node@v4`. [verified]
- Under test: all of `src/` (see coverage); JS tests import `src/Resources/app/administration/src/service/{channel-scope,presets,settings-scope}.js`. [verified]
- Seed/leak check rely on the plugin's route `GET /api/_action/mn-channel-scope/me` and CLI command `mn:channel-scope:preset` (ApplyPresetCommand). [verified]
- Used by: CI workflow only; README.md and CHANGELOG.md link to docs/testing.md. [verified]

## Patterns and conventions
- Static doubles instead of mocks for the plugin's own seams. Interfaces in src (RoleChannelLoader, ScopeLookup, CategoryTreeSource, CmsUsageSource, PromotionSource) have `tests/Static*.php` in-memory implementations; `Ids` gives two channels (`DE`, `EN`, plus an unused-by-docs `B2B`), `DE_USER` limited to DE and `FREE_USER` unrestricted, with `Ids::scope()` and `Ids::context()`. [verified: tests/Ids.php, tests/Static*.php]
- Mocks are still used, but for Shopware/Symfony types, not plugin seams: `createMock` for `EntityWriteGatewayInterface`, `EntityRepository`, `DefinitionInstanceRegistry`, `HttpKernelInterface`, `WriteCommand` subclasses, `EntityDefinition` (14 occurrences across 9 files). So docs/testing.md "replace the database with small test doubles" is accurate, but "static doubles instead of mocks" is not absolute. [verified: grep createMock in tests/Unit]
- Write guards are tested by building commands with Shopware's `StaticDefinitionInstanceRegistry` and dispatching a `PreWriteValidationEvent`; read rules called directly or via `ReadFilterSubscriber`. Every guard test also checks unrestricted users are unaffected. [verified: docs/testing.md; sampled test files, the "every" claim is [inferred]]
- PHPUnit attributes `#[CoversClass(...)]` on test classes (27 attributes across 25 class names). Test layout mirrors `src/` (Unit/Rule, Unit/Subscriber, ...). `declare(strict_types=1)` everywhere. [verified]
- A typical change: add/modify a rule or guard in src, add a test with the five cases listed in docs/testing.md "For a new rule" (own channel visible/changeable, foreign hidden/refused, move/create refused, same-write inserts accepted, unrestricted never refused); for a new admin override add the component to tests/ci/admin-overrides.json. If a new record type reaches a sales channel via associations the leak sweep picks it up automatically; id-only references need a bespoke probe. [verified: docs/testing.md]
- Optional Commercial: `WriteGuardSubscriberTest` skips the review-summary test when `ProductReviewSummaryDefinition` is absent; PHPStan has `reportUnmatchedIgnoredErrors: false` for the same reason. [verified: tests/Unit/Subscriber/WriteGuardSubscriberTest.php:287, phpstan.neon.dist]

## Tests

### CI (.github/workflows/tests.yml) [verified]
Triggers: push to `main`, tags `v*`, every pull request. Jobs:
- `static` (PHPStan, then `composer test`), matrix: PHP 8.2 with `--prefer-lowest`, PHP 8.3 and 8.4 with highest, all on Shopware 6.7 as resolved by `composer update` (version printed via `composer show shopware/core`). Extensions intl, pdo_mysql, zip, gd; no coverage driver.
- `integration`, matrix Shopware `v6.7.14.0` (composer.json minimum), `v6.7.16.0`, `trunk` (`continue-on-error`): installs plugin through `shopware/github-actions/setup-extension@main` (`allow-insecure-versions: true`), then override check, seed + six presets, leak check against `php -S 127.0.0.1:8000 -t public public/index.php` (waits up to 30 s on `/api/_info/version`). The PHP unit suite does not run inside the shop in CI.
- `js`: Node 20, the node test command.
- `presets`: JSON validity of src/Resources/config/presets/*.json.
No PHP version for the integration job is set in the file (defaults of the action) [inferred]. No release/packaging step; no coverage upload.

### Counts
About 128 test methods in 25 PHP test files (grep `public function test`/`#[Test]`, rough) and 11 `test()` cases in 3 JS files. [verified by grep, approximate]

### Coverage per source area (by `CoversClass` and test file names; no coverage tool run)
Tested [verified]: Scope (ChannelScope, ConfigRules, ScopeRules, SettingsDomains), Rule (SalesChannelColumnRule, ParentChannelRule, OrderDescendantRule, WriteScope), Category (CategoryTrees), Cms (CmsPages), Preset (PermissionPresets, PresetDirectory), Controller (ChannelScopeController), Exception.
All 13 subscribers in src/Subscriber have a test (AdminSearchGuard, ChannelParameterGuard, ChannelTotals, CloneGuard, CmsGuard, ImitateCustomerGuard, PromotionGuard, ReadFilter, StateHistoryGuard, SystemConfigGuard, ThemeGuard, WriteGuard (largest, 417 lines), WriteRuleSubscriber under Unit/Rule), plus tests/Unit/Subscriber/CategoryWriteGuardTest.php. CHANGELOG 0.12.x says a unit test checks that every guarded product detail is also read-filtered. [verified]
No unit test [verified by grep, no reference in tests/Unit]:
- All Dbal* implementations: src/Scope/DbalRoleChannelLoader.php, src/Rule/DbalChannelColumnLookup.php, src/Write/DbalScopeLookup.php, src/Category/DbalCategoryTreeSource.php, src/Cms/DbalCmsUsageSource.php, src/Promotion/DbalPromotionSource.php, src/Totals/DbalChannelTotals.php (SQL is only exercised indirectly by the leak check in CI [inferred]).
- src/Command/ApplyPresetCommand.php (run in CI via the shell loop, not asserted), src/Dashboard/ScopedOrderAmountService.php, src/Extension/AclRoleExtension.php, src/Entity/AclRoleSalesChannelDefinition.php, both src/Migration/*, src/MnSalesChannelScope.php, src/Tags.php, src/Resources/config/{services,routes}.php (DI wiring untested apart from the CI shop boot [inferred]).
- Administration: only `service/channel-scope.js`, `presets.js`, `settings-scope.js` are tested. `service/api.js`, `service/public-api.js`, `main.js`, and all 14 component overrides in src/Resources/app/administration/src/extension have no behavioural test; the overrides are only checked for the existence of the upstream hooks. The built bundle in src/Resources/public is not checked against sources [inferred; README line 124 says it is committed manually].
- Storefront and the Admin UI end to end: only the manual acceptance checklist.
Rough impression: strong coverage of rule/guard logic in isolation; SQL, wiring, migrations and UI rely on CI integration (leak check) and manual checks.

## Hotspots
git log over tests/.github/config (all commits 2026-11-10 to 2026-11-13): composer.json 21 changes (mostly version bumps [inferred]); tests/Unit/Subscriber/WriteGuardSubscriberTest.php 9; ReadFilterSubscriberTest.php 8; tests/StaticScopeLookup.php 6; PermissionPresetsTest.php 4; docs/testing.md 4; tests/bootstrap.php 3; tests.yml 3; seed.php 2. Most recent CI work: 5e38952 (seed without .env, explicit filter narrowing for older PHPStan), eb78dd0 (0.12.0 leak check), f318658 (0.11.3 compatibility checks), 4545b60 (0.12.1). [verified: git log]

## Risks
- docs/testing.md vs reality: matches on suites, matrix, doubles list and leak-check description. Differences: (a) "in a shop" instructions run PHPUnit via `php /path/to/phpunit.phar`, while CI never runs unit tests inside a shop, so the Commercial-enabled path is never exercised in CI [verified]; (b) the doc's Ids row omits `B2B` constant [verified]; (c) the doc's CI bullet says "on every push and pull request", but push is restricted to `main` and `v*` tags [verified]; (d) the leak-check section lists three phases while the script also does a plain-search/translations pass (described in CHANGELOG 0.12.x, not in docs) [verified]; (e) README line 124 says "See docs/testing.md" for building administration sources, but testing.md does not cover it, and `bin/build-administration.sh` is not in this repo (only bin/expand-preset.js) [verified].
- Leak check is only as strong as its seed: one foreign channel, one limited user with a single own channel, a role reading everything. Foreign data is created for a fixed list of entities; entities with no seeded record pass trivially (a `total` of 0 cannot tell "filtered" from "no data") [inferred]. Entities in `NOT_FOLLOWED` and the 2-paths-per-entity cap are blind spots [verified list, impact inferred].
- ACCEPTED exceptions (`product_visibility`, `main_category`) are deliberately visible and so a documented leak. [verified]
- Seed picks the Storefront and the API channel by type (`ORDER BY created_at LIMIT 1`); a shop with other channel setups breaks it. [verified]
- Seed runs with `display_errors` to stderr and relies on the kernel booting in prod mode; a stale-container situation would break it [inferred].
- `setup-extension@main` is an unpinned moving ref; `trunk` failures are ignored. [verified]
- check-admin-overrides.php uses regexes over concatenated files; a name present in a comment, or a different component with a same-named directory, passes. [inferred]
- `leak-check.php` is excluded from PHPStan and has no tests of its own; its exit code semantics (2 for setup errors) are not documented in docs/testing.md. [verified]
- The `lowest` job uses `--prefer-lowest` for all deps, so PHPStan/PHPUnit versions also go to their lowest; it is unclear whether that is intended [inferred].
- composer.json has no `leak-check` or `check-admin-overrides` script, so those two suites are invoked only by long raw commands. [verified]

## Open questions
- Why is the leak check a standalone curl script and not a PHPUnit integration test? (docs say it "runs against any shop"; the rationale is not stated.)
- Why are `category`, `cms_page`, `theme` and others in `NOT_FOLLOWED` skipped by the sweep instead of probed? The comment says "limited differently"; which tests cover that is not documented.
- Is the Commercial-enabled unit path meant to be run by hand before release, and by whom?
- Why is the minimum tested Shopware 6.7.14.0? compatibility.md says the override check "found three gaps before 6.7.14", but no test records which.
- Should DbalScopeLookup and the other SQL classes get integration tests, or is the leak check the intended safety net?
- Which PHP version does the `integration` job run on (set by the setup-extension action, not in this repo)?

## Verified by /onboard (2026-11-17)
All suites run on 2026-11-17 in a dockware Shopware 6.7.16 shop (PHP 8.2, Node 24): unit 128 tests (1 skipped), PHPStan level 8 no errors, JS 11 tests with `node --test tests/js/*.test.mjs`, preset JSON valid, override check 14 components / 0 problems, seed + six presets + leak check "No leaks found" [verified].
`node --experimental-detect-module --test tests/js/` (composer test:js, CI) fails on Node 24 with "Cannot find module '/…/tests/js'": a directory argument only works on Node 20 [verified].
The leak check passed although S1–S3 (see docs/system/overview.md) exist: it covers reads of entities and five endpoints, not settings values or writes [verified].
