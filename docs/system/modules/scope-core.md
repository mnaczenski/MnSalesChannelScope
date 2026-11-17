# Module: Scope core
_Path: src/Scope, src/Entity, src/Extension, src/Migration, src/Exception, src/MnSalesChannelScope.php, scope wiring in src/Resources/config/services.php (plugin version 0.12.1, composer.json:5)_

Legend: [verified] = seen in code, tests or git; [inferred] = my interpretation. Paths are relative to the plugin root (/Users/M.Naczenski/Documents/shopware/rotfuchs-deploy/MnSalesChannelScope). Git works here (git log used).

## Responsibility
Decides, per admin request, which sales channels the caller is limited to (`ChannelScope`, null = unrestricted) from the channels stored on ACL roles, and holds the built-in read filters per entity (`ScopeRules`) and the rules for `system_config` keys (`ConfigRules`, `SettingsDomains`). It also owns the role-to-channel table, its DAL definition, the `acl_role.mnSalesChannels` association, the shared error codes and the plugin class. [verified: src/Scope/ChannelScope.php:9-17, src/Scope/ScopeRules.php:16-27, src/Entity, src/Extension]
Every other module (read filter, write guards, route guards, presets, administration) asks this module "which channels?" and does the enforcing itself. [verified: 23 call sites of `salesChannelIds(`/ChannelScope in src, wiring in services.php:66-170]

## Entry points and interfaces
| What | Where | Notes |
|---|---|---|
| `ChannelScope::salesChannelIds(Context): ?array`, `isRestricted()`, `reset()` | src/Scope/ChannelScope.php:29, 52, 57 | Public API per docs/compatibility.md:12. Returns lowercase hex ids or null. [verified] |
| `RoleChannelLoader::load(string $userId): array<roleId, list<channelId>>` | src/Scope/RoleChannelLoader.php:12 | Interface = extension point ("decorate it", docs/extending.md:271). Empty list for a role = unrestricted role. [verified] |
| `DbalRoleChannelLoader` (@internal) | src/Scope/DbalRoleChannelLoader.php:17-37 | One SQL: acl_user_role INNER JOIN acl_role (deleted_at IS NULL) LEFT JOIN mn_acl_role_sales_channel. [verified] |
| `ScopeRules implements ReadRule` | src/Scope/ScopeRules.php:27, `readFilter()` at :109-141 | Big `match(true)` entity -> Filter. Tagged `mn_channel_scope.read_rule`, priority 100 (services.php:84-86). Constant lists PROMOTION_CHILDREN, LANDING_PAGE_CHILDREN, CMS_PARTS, ORDER_CHILDREN, PRODUCT_CHILDREN, CHANNEL_SETTINGS, CHANNEL_MAPPINGS (:40-104) are reused by other modules/tests (the "every guarded product detail is also read-filtered" test, CHANGELOG 0.12.0). [verified for constants; reuse inferred] |
| `ConfigRules::isReadable/isEditable/readableDomains/hiddenDomains` | src/Scope/ConfigRules.php:44-68 | Prefix match on whole key segments (`$domain.`), hidden wins over readable/editable. Fed by tagged `SettingsDomains`. [verified] |
| `SettingsDomains` (value object, `shopware()` defaults) | src/Scope/SettingsDomains.php:16-60 | Extension point, tag `mn_channel_scope.settings_domains` (src/Tags.php:15, services.php:82). [verified] |
| `ChannelScopeException` (WRITE_DENIED, SETTINGS_DENIED, ROUTE_DENIED, all HTTP 403) | src/Exception/ChannelScopeException.php:10-42 | Public error codes. [verified] Docs say write refusals surface as HTTP 400 (architecture.md "Write guard"); this class says 403, and writeDenied is probably converted by the write subscriber into a WriteConstraintViolation [inferred, not checked: belongs to the guards module]. |
| `AclRoleSalesChannelDefinition` (mapping `mn_acl_role_sales_channel`) | src/Entity/AclRoleSalesChannelDefinition.php:17-34, tag at services.php:60-61 | MappingEntityDefinition, composite PK. [verified] |
| `AclRoleExtension` -> `acl_role.mnSalesChannels` (ManyToMany, ApiAware only for AdminApiSource) | src/Extension/AclRoleExtension.php:21-27, services.php:62 | Used by the role page in the administration. [verified] |
| Plugin class, uninstall cleanup | src/MnSalesChannelScope.php:11-23 | Drops `mn_acl_role_sales_channel` unless keepUserData. [verified] |
| Service wiring | src/Resources/config/services.php:60-86 (scope part) | ChannelScope tagged `kernel.reset` (:66-68). [verified] |

### How the scope is resolved per request (security-relevant) [verified in src/Scope/ChannelScope.php:29-50 unless noted]
Order of checks, first hit returns null (unrestricted):
1. `Context::getScope() === SYSTEM_SCOPE` (line 31).
2. source is not `AdminApiSource` (storefront, Store API, SystemSource, CLI default context) or `isAdmin()` (line 36).
3. `getUserId() === null`, i.e. integrations / API keys (line 40). Unit test: tests/Unit/Scope/ChannelScopeTest.php:41-53.
4. Otherwise roles are loaded from the DB. No roles -> null; any role with zero channels -> null (lines 67-76); else union of channels, lowercased.
Caching: in-process array keyed by user id (line 19, 45-47), cleared by `reset()` (kernel.reset tag, services.php:68). Under php-fpm that means once per request; in long-running workers it lasts until Symfony's reset (per message/request). Test: ChannelScopeTest.php:65-76. The cache is not invalidated when roles change within one request. [verified; consequences inferred]
CLI: console commands have no AdminApiSource, so they are unlimited by design (docblock lines 12-15, docs/architecture.md "Who is limited"). The preset command writes `acl_role` directly via DBAL (src/Command/ApplyPresetCommand.php:22,39). [verified]

## Data model
- `mn_acl_role_sales_channel(acl_role_id BINARY16, sales_channel_id BINARY16)`, PK on both, FK to `acl_role` and `sales_channel`, both ON DELETE CASCADE. Created in src/Migration/Migration1762780000RoleSalesChannel.php:22-32. Semantics "role without rows = unrestricted" (docblock :9). [verified]
- `mn_cms_page_sales_channel(cms_page_id, cms_page_version_id, sales_channel_id)` created by src/Migration/Migration1763900000CmsPageSalesChannel.php:23-34 ("owner" channels of an unused layout). It is read/written with raw SQL in src/Cms/DbalCmsUsageSource.php:81,119; the table belongs functionally to the Cms module but its migration is in this module. No DAL definition exists for it and the plugin's uninstall does not drop it (src/MnSalesChannelScope.php:21 drops only the role table). [verified]
- No entity/collection classes; only the mapping definition. Both migrations only implement `update()` with `CREATE TABLE IF NOT EXISTS` (idempotent, no destructive step). [verified]
- Reads used by the scope: `acl_user_role`, `acl_role.deleted_at` (DbalRoleChannelLoader.php:20-24). [verified]

## Dependencies
- Uses: Shopware Core (`Context`, `AdminApiSource`, DAL filters, `MappingEntityDefinition`, `MigrationStep`, `HttpException`, `Plugin`), Doctrine DBAL `Connection` [verified, imports]. Plugin modules: `Category\CategoryTrees` and `Cms\CmsPages` (injected into ScopeRules for the `category` and `cms_*` filters, ScopeRules.php:5-6,31-35,121,132-135), `Rule\ReadRule` interface (ScopeRules.php:27). [verified] Shopware version: `~6.7.14` (composer.json:13-14).
- Used by (all verified via grep/services.php): ReadFilterSubscriber, WriteGuardSubscriber (also ConfigRules, and the role-mapping rule at src/Subscriber/WriteGuardSubscriber.php:284-286), WriteRuleSubscriber, CmsGuardSubscriber, CloneGuardSubscriber, PromotionGuardSubscriber, ThemeGuardSubscriber, SystemConfigGuardSubscriber (ConfigRules), ChannelParameterGuardSubscriber, ImitateCustomerGuardSubscriber, StateHistoryGuardSubscriber, AdminSearchGuardSubscriber, ChannelTotalsSubscriber, ScopedOrderAmountService, ChannelScopeController, OrderDescendantRule (mentions scope) and the administration (`GET /api/_action/mn-channel-scope/me`, via the controller). Plugin outside code can decorate `RoleChannelLoader`, tag `SettingsDomains`, call `salesChannelIds()` (docs/extending.md:19-25).

## Patterns and conventions
- Plain classes with constructor injection, services declared in PHP (services.php) with explicit `->args([service(...)])`, `Dbal*` implementation behind an interface alias (`RoleChannelLoader`, `CategoryTreeSource`, `ScopeLookup`, ...) so tests use `tests/Static*` fakes. [verified services.php:64-65,69-73]
- Fail-closed intent in rules, but "null = no filter" is the convention for unknown entities (ScopeRules.php:140). [verified]
- Typical change: (a) new entity visible per channel: add the entity to one of the constant lists or a `match` arm in ScopeRules.php:109-141 (or tag a `ReadRule`), add the matching write guard, extend the leak check (tests/acceptance/leak-check.php) and a unit test; (b) new settings page: add domain to `SettingsDomains::shopware()` (:35-60) or register a tagged `SettingsDomains`; (c) schema change: new `Migration<timestamp>...` file. [inferred from CHANGELOG 0.6-0.12 and docs/extending.md]
- Every filter is written against the real DAL field names (e.g. `salesChannels.id`, `promotion.salesChannels.salesChannelId`), using many-to-many / association paths rather than raw SQL. [verified ScopeRules.php:109-141]

## Tests
- Unit, no DB: tests/Unit/Scope/ChannelScopeTest.php (6 tests: single role, union, one free role removes limit, unrestricted sources incl. integration/system scope/SalesChannelApiSource/users without roles, system scope, cache per request until reset) and tests/Unit/Scope/ConfigRulesTest.php (2 tests). Fake: tests/StaticRoleChannelLoader.php, ids helper tests/Ids.php. [verified]
- No direct unit test of `ScopeRules::readFilter` or `DbalRoleChannelLoader` under tests/Unit/Scope; ScopeRules is exercised indirectly through tests/Unit/Subscriber/ReadFilterSubscriberTest.php and the leak check. [verified for files present; coverage of ScopeRules beyond that not checked]
- Run: `composer test` (phpunit --testsuite unit), `composer phpstan` (composer.json:scripts). End-to-end: tests/acceptance/leak-check.php against a running shop, seeded by tests/ci/seed.php (CHANGELOG 0.12.0, 0.11.3 describe CI). Not run here (no PHP). [verified]
- Not covered by any test I found: migrations, uninstall, the SQL of DbalRoleChannelLoader, the DAL definition/extension. [inferred from the file list]

## Hotspots
git log --oneline per file (11 commits total on the repo, 2026-11-10 to 2026-11-13, so "hotspot" = touched by most feature releases):
- src/Resources/config/services.php: 11 commits, every feature adds wiring (196 lines). [verified]
- src/Scope/ScopeRules.php: 10 commits, grows with each iteration (0.2 -> 0.12.1, last change 0.12.1 product translation grouping fix). Constant lists at :40-104 and `match` at :109-141. [verified]
- src/Scope/ConfigRules.php: 3 commits; src/Exception/ChannelScopeException.php: 3 commits. [verified]
- ChannelScope.php, DbalRoleChannelLoader.php, SettingsDomains.php, AclRoleExtension.php, MnSalesChannelScope.php: 1 commit each, effectively stable since 0.2.0. [verified]
Recently active: 0.12.0 (security review, 2026-11-11) and 0.12.1 (2026-11-12). [verified git log]

## Risks
1. Fail-open when a role loses all its channels. FK ON DELETE CASCADE on `sales_channel_id` (Migration1762780000...:29-30) deletes mapping rows when a channel is deleted; a role whose only channel was deleted then has zero channels and is "unrestricted" (ChannelScope.php:74-76). Same for roles soft-deleted (`deleted_at`) leaving a user with only free roles, and for a user whose only roles are deleted: `$roles === []` -> null (:68-70). Sales channel deletion by limited users is blocked (WriteGuardSubscriber.php:274-277) but an admin or API can still do it. [verified code; impact inferred]
2. "Unrestricted" is the default of every uncertain case: integrations (ChannelScope.php:40, test :48), system scope (:31), non-admin sources, users without roles. Any admin-API route that runs work in system scope or through another source bypasses the filters unless separately guarded (docs/architecture.md "Paths around the data layer" lists the known ones). Not documented there: whether the admin Store API proxy (`/api/_proxy/store-api/...`) uses a SalesChannelApiSource context (-> unlimited by the rule at :36). The doc says it "reads through the repositories, none needed" (architecture.md) [inferred open point, not verified; ask author].
3. Privilege escalation via role administration is only partly guarded here: the role-mapping write is refused for limited users (WriteGuardSubscriber.php:284-286), but I found no guard in src for `acl_role`, `acl_user_role` or `user` writes (grep, no hits). A limited user who holds `acl_role:*`/`acl_user_role:*`/`user:*` privileges could create a role without channels or attach one to themselves and become unlimited. The shipped presets contain `user:read` but I found no `acl_role`/`acl_user_role` grants (src/Resources/config/presets/orders.json:120; grep). So safety rests on presets and on not granting those privileges manually. [verified grep result; exploit path inferred, untested; belongs to guards module]
4. Per-process cache not invalidated on role change (ChannelScope.php:19,45); relevant for long-running workers/tests, and any code path that changes a user's roles and then reads in the same request. Admin session JWT carries no channel info, so a role change takes effect on the next request. [verified cache; consequence inferred]
5. `ScopeRules::readFilter` returns null (no filter) for any entity not listed (:140), and for `category` when `CategoryTrees` is null (:121) and for cms parts when `CmsPages` is null (:132). Constructor defaults are null (:31-35) but services.php always injects them. Entities of other plugins/new core entities are open until a rule is added; the leak check sweeps the schema to find these (docs/architecture.md "Limitations"). [verified]
6. Uninstall leaves `mn_cms_page_sales_channel` behind (src/MnSalesChannelScope.php:21), and uninstall without keepUserData drops the role mapping, making every limited role unrestricted afterwards (inferred: plugin gone, so no filters at all anyway). [verified for drop list]
7. Migration for the Cms table lives in the Migration folder of this module but the table is used by Cms; `CREATE TABLE IF NOT EXISTS` with FK to `cms_page(id, version_id)` makes future schema changes of either table need care. [inferred]
8. Docs vs code mismatches:
   - docs/architecture.md (read filter table) lists `product_review_summary` with `salesChannelId IN (...)`; code puts it in CHANNEL_SETTINGS with `OR salesChannelId IS NULL` (ScopeRules.php:81-89,136-139). [verified]
   - architecture.md says refusals of writes are HTTP 400 with WRITE_DENIED; `ChannelScopeException` uses 403 for all three codes (:16,26,36) [verified exception; the 400 path is in the guards module, unverified].
   - architecture.md says integrations are "not covered yet"; code and test treat them as deliberately unrestricted (ChannelScope.php:40) - consistent, but listed as a limitation to fix, not a decision. [verified]
   - ScopeRules docblock (:20-25) lists iterations 1-2 and hardening but not wishlists/newsletter/promotions/landing pages explicitly; the code handles them in other rules/arms. Cosmetic. [verified]
   - `ScopeRules`'s `ReadRule` use of `CHANNEL_SETTINGS` for `sales_channel_file`/`product_export` assumes a nullable `sales_channel_id` column with the property name `salesChannelId` [inferred; relies on Shopware field names, check on upgrade].
9. Customer read filter joins `orderCustomers.order.salesChannelId` (ScopeRules.php:189): documented performance risk (architecture.md "Limitations: Performance"). [verified]
10. `ConfigRules` prefix matching uses `core` readable minus a hidden list (SettingsDomains.php:45-57): any new core.* credential domain added by Shopware is readable by default until added to `hidden` (allow-by-default for the readable part). [verified from code; consequence inferred]
11. No `Tags`/services wiring check that `RoleChannelLoader` decorators fail closed; a decorator returning `[]` makes everyone unrestricted (ChannelScope.php:68). [inferred]

## Open questions
- Is unrestricted-by-default for integrations / no-role users / deleted roles intended long-term, or only for the proof of concept? Who: product owner / plugin author.
- Should deleting the last mapped sales channel of a role make the role unrestricted (current cascade behaviour), or should it lock the role? Who: plugin author.
- Why is the cache not tied to role-change events, and is the Admin API ever used in a long-running worker (Swoole/RoadRunner)? Who: plugin author.
- Are `acl_role`/`acl_user_role`/`user` writes meant to be refused for limited users (escalation), or is that left to not granting the privileges? Who: plugin author / product owner.
- How does the admin Store API proxy / admin order creation resolve scope (source of the context)? Who: plugin author (check against Shopware 6.7 `_proxy/store-api`).
- Why does `mn_cms_page_sales_channel` live in this module's migrations and why is it not dropped on uninstall? Who: plugin author.
- Is write failure HTTP 400 (docs) or 403 (exception class) the intended contract? Who: plugin author.

## Verified by /onboard (2026-11-17)
S3 [verified]: with `acl_role:create` and `acl_user_role:create` added to the CI role (not part of any preset), the limited user created a role without channels (token with the `user-verified` scope), assigned it to themselves and, after a new login, read the foreign order (404 before, 200 after).
S4 [verified in code, not reproduced]: `ChannelScope::resolve()` treats an empty channel list as unrestricted and the mapping rows cascade on channel delete; deleting the CI role's channel was refused by Shopware (FK 1451, the channel has orders), so the role stayed limited. Applies to channels without orders.

S3 and S4: accepted as known limitations, documented in README and docs/architecture.md (ADR-0008).
