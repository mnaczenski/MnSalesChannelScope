# Module: read-write-rules (Read & write rules)
_Path: src/Rule/, src/Write/, src/Subscriber/ReadFilterSubscriber.php, src/Subscriber/WriteGuardSubscriber.php, src/Subscriber/WriteRuleSubscriber.php, wiring in src/Resources/config/services.php_

Marks: [verified] = seen in code, tests or git of this repo; [inferred] = deduced, not confirmed. Paths are relative to the plugin root `/Users/M.Naczenski/Documents/shopware/rotfuchs-deploy/MnSalesChannelScope`. "Reference checkout" = a Shopware core clone at `/Users/M.Naczenski/Documents/product-analytics/shopware` (HEAD 26cf0d12380, 2026-07-20); its version is not the rotfuchs prod version, so statements taken from it are [inferred] for prod.

## Responsibility

For a restricted admin user (every role has sales channels, see `src/Scope/ChannelScope.php:29-50`), this module (a) adds sales-channel filters to every repository search and aggregation, including to-many associations loaded with it, and (b) refuses every write command (insert, update, delete, also Sync API, nested and versioned writes) that would touch a record outside the user's channels. Records shared with other channels are visible but read-only. It is also the plugin's public extension point: `ReadRule` / `WriteRule` services tagged `mn_channel_scope.read_rule` / `mn_channel_scope.write_rule` [verified: `src/Tags.php`, `docs/extending.md:33-152`].

Unrestricted callers (admins, integrations, Store API, CLI, system scope, users with any role without channels) pass through untouched [verified: `src/Scope/ChannelScope.php:31-43,73-80`; both subscribers return early when `salesChannelIds()` is null: `ReadFilterSubscriber.php:60-63`, `WriteRuleSubscriber.php:34-37`, `WriteGuardSubscriber.php:111-114`].

## Entry points and interfaces

| What | Where | Notes |
|---|---|---|
| Read filter | `src/Subscriber/ReadFilterSubscriber.php:40-56` (`EntitySearchedEvent`, `BeforeEntityAggregationEvent`) | asks every `ReadRule` for a filter, ANDs them (`:96-111`), then walks `$criteria->getAssociations()` to depth 8 and filters to-many (OneToMany, ManyToMany) associations only (`:116-144`) [verified] |
| Write guard (built-in, monolithic) | `src/Subscriber/WriteGuardSubscriber.php:104-144` (`PreWriteValidationEvent`) | hard-coded per-entity branches: `denyGlobal` (`:153`), `deny` (`:277`: orders, order children, customers, sales channels, landing pages), `denyProduct` (`:408`: products, product children, visibilities, reviews, channel mappings, analytics, themes, review summaries, sortings, system_config, channel settings), `denyCategory` (`:221`). Not a `WriteRule` and not tagged [verified: `services.php:133-135`] |
| Write rules (pluggable) | `src/Subscriber/WriteRuleSubscriber.php:32-50` (`PreWriteValidationEvent`) | builds one `WriteScope` per write, passes each command to each tagged rule, first reason per command wins (`break`, `:46`), reason added as `ChannelScopeException::writeDenied` [verified] |
| `ReadRule` interface | `src/Rule/ReadRule.php:16-24` | `readFilter(entity, salesChannelIds): ?Filter` |
| `WriteRule` interface | `src/Rule/WriteRule.php:18-25` | `deny(WriteCommand, WriteScope): ?string`; commands in storage format (bytes ids) |
| `WriteScope` | `src/Rule/WriteScope.php:15-106` | `isOwn()`, `ownsAll()`, `insertOf(entity, hexId)` (records inserted in the same write), `commands()`, `context()` |
| `SalesChannelColumnRule` | `src/Rule/SalesChannelColumnRule.php:28-70` | generic read+write rule for an entity with `sales_channel_id`; `$allowAllChannels` option makes NULL-channel records visible (read-only) |
| `ParentChannelRule` | `src/Rule/ParentChannelRule.php:19-77` | records that follow a parent with a channel column (read: `<association>.salesChannelId`) |
| `OrderDescendantRule` | `src/Rule/OrderDescendantRule.php:23-114` | built-in: delivery positions, download items, captures, refunds, refund positions, document files; walks parents up to depth 6 |
| `ChannelColumnLookup` / `DbalChannelColumnLookup` | `src/Rule/ChannelColumnLookup.php`, `src/Rule/DbalChannelColumnLookup.php:18-40` | one raw SQL `SELECT HEX(col) FROM table WHERE pk...`; identifiers checked with `/^[a-z_][a-z0-9_]*$/` |
| `ScopeLookup` / `DbalScopeLookup` | `src/Write/ScopeLookup.php`, `src/Write/DbalScopeLookup.php` (242 lines) | 17 stored-fact lookups (order channel, customer channels, product visibilities with parent fallback, setting channel, theme channels, landing page channels, ...), one SQL query each |
| Service tags | `src/Tags.php:10-19` | `READ_RULE`, `WRITE_RULE` (plus preset and settings-domain tags owned by other modules) |
| Wiring | `src/Resources/config/services.php:84-86` (`ScopeRules`, priority 100), `:88-115` (lookup, `WriteRuleSubscriber`, newsletter / order-descendant / wishlist rules), `:121-123` (`ReadFilterSubscriber`), `:131-135` (`DbalScopeLookup`, `WriteGuardSubscriber`), `:155-158` (`StateHistoryGuardSubscriber` is also tagged `WRITE_RULE`) | all verified |
| Error | `Mn\SalesChannelScope\Exception\ChannelScopeException::writeDenied` -> code `MN_CHANNEL_SCOPE__WRITE_DENIED`, HTTP 400 [verified: `WriteRuleSubscriber.php:44`, `docs/architecture.md`, `docs/extending.md:150`] |

The built-in read rule is `ScopeRules` (`src/Scope/ScopeRules.php:27`, owned by the scope-core module); it is registered as a `READ_RULE` like any other [verified: `services.php:84-86`]. Entity lists shared between read and write live there as constants (`ORDER_CHILDREN`, `PRODUCT_CHILDREN`, `CHANNEL_SETTINGS`, `CHANNEL_MAPPINGS`, `PROMOTION_CHILDREN`, `CMS_PARTS`, lines 40-104).

## Data model

No tables, entities or migrations of its own [verified: nothing in the listed paths declares a definition]. It only reads existing Shopware tables with raw SQL: `order`, `customer`, `customer_address`, `product`, `product_visibility`, `main_category`, `product_review`, `system_config`, `landing_page_sales_channel`, `theme_sales_channel`, `sales_channel`, `sales_channel_analytics`, `order_delivery`, `order_transaction`, plus any `<table>` / `<column>` passed to `DbalChannelColumnLookup` and `parentOf` (`DbalScopeLookup.php:20-240`). The role-to-channel table `mn_acl_role_sales_channel` belongs to scope-core.

## Entity map (built from code)

Read = what a restricted user sees (filter, `ScopeRules.php:111-141` unless noted). Write = what the guard allows. "children" = through the parent. Abbreviations: `own` = in the user's channel set; `shared` = also in another channel.

| Entity | Rule (class, line) | Read behaviour | Write behaviour |
|---|---|---|---|
| `sales_channel` | ScopeRules :112 / WriteGuard :288 | `id IN own` | update only own; insert and delete refused; entry categories must be editable (`:296-301`) |
| `order` | ScopeRules :113 / WriteGuard :306 | `salesChannelId IN own` | stored channel must be own (version-aware lookup); payload channel must be own; insert only into own |
| `order_line_item`, `order_delivery`, `order_transaction`, `order_address`, `order_customer`, `document` (`ORDER_CHILDREN`) | ScopeRules :114 / WriteGuard :320 | `order.salesChannelId IN own` | stored order must be own; `order_id` in payload must be own or inserted in the same write |
| `order_delivery_position`, `order_line_item_download`, `order_transaction_capture`, `order_transaction_capture_refund`, `order_transaction_capture_refund_position`, `document_file` | OrderDescendantRule :26-34 | `<path>.salesChannelId IN own` (refund position via `orderLineItem.order.`, others via their parent chain) | stored record's order must be own (parent walk, `:77-113`); on insert or parent change the parent's order must be own |
| `state_machine_history` | StateHistoryGuardSubscriber (other module; also tagged `WRITE_RULE`, `services.php:155-158`) | only transitions of own orders/deliveries/transactions | new ones for own; never changed (per `docs/architecture.md`, not re-read here) [inferred] |
| `customer` | ScopeRules :115,184 / WriteGuard :340 | `salesChannelId` OR `boundSalesChannelId` OR `orderCustomers.order.salesChannelId` in own | update/delete only if registered in or bound to own; new `sales_channel_id` / `bound_sales_channel_id` must be own. Customers who only ordered in own channel are read-only |
| `customer_address`, `customer_recovery` | ScopeRules :116 / WriteGuard :358-378 | customer rule through `customer.` | their customer must be registered in or bound to own (stored and payload) |
| `customer_wishlist` | SalesChannelColumnRule (`services.php:108-111`) | `salesChannelId IN own` | stored and payload channel must be own, NULL refused |
| `customer_wishlist_product` | ParentChannelRule (`services.php:112-115`) | `wishlist.salesChannelId IN own` | stored wishlist and new wishlist (if changed) must be own; wishlist inserted in the same write accepted |
| `newsletter_recipient` | SalesChannelColumnRule (`services.php:96-99`) | `salesChannelId IN own` | as wishlist |
| `product` | ScopeRules :117,171 / WriteGuard `denyProduct` :415 | `visibilities.salesChannelId IN own` OR `parent.visibilities.salesChannelId IN own` | update/delete only if ALL channels of the product are own (`owns`); "touch only" updates (`updated_at`, `updated_by_id`) need only one own channel (`:431-434`); insert needs a visibility in own in the same write, or a variant of an owned parent |
| `product_price`, `product_media`, `product_configurator_setting`, `product_cross_selling`, `product_download`, `product_search_keyword`, `product_translation` | ScopeRules `PRODUCT_CHILDREN` :118 / WriteGuard `PRODUCT_WRITE_CHILDREN` :443 | `product.<product rule>` | product must be wholly own |
| `product_category`, `product_option`, `product_property`, `product_tag`, `product_custom_field_set` | none on read; WriteGuard :443 | not filtered (mapping tables, assumed to have no search route: test comment `ReadFilterSubscriberTest.php:211`) | product must be wholly own; `product_category` also needs a visible category (`denyCategory` :261) |
| `product_visibility` | ScopeRules :118 / WriteGuard :452 | via product rule (a shared product shows only the visibilities of own channels, [inferred] from the association filter `:135-139`) | stored and payload channel must be own, product must be visible to the user (also when shared) |
| `main_category` | ScopeRules :120 / WriteGuard :452 | `salesChannelId IN own` | as `product_visibility`, plus category must be visible (`:261`) |
| `product_review` | ScopeRules :119 / WriteGuard :472 | `salesChannelId IN own` | stored and payload channel own |
| `product_review_summary` (Commercial) | `CHANNEL_SETTINGS` :136 | own OR NULL channel | stored channel must be own, NULL refused; insert must name own channel |
| `product_review_summary_translation` | ScopeRules :128 / WriteGuard :516 | via `productReviewSummary.salesChannelId` | summary's stored channel must be own |
| `category` | ScopeRules :121 via `CategoryTrees` / WriteGuard `denyCategory` :232 | entry categories, their ancestors and subtrees | editable only if only own channels use it; no root categories; no move/delete over another channel's entry category |
| `category_translation`, `category_tag` | WriteGuard :255 (read: no rule) | not filtered [inferred] | category must be editable |
| `cms_page`, `cms_section`, `cms_block`, `cms_slot` | ScopeRules `CMS_PARTS` :132 | visible layout ids from `CmsPages` | `CmsGuardSubscriber` (other module) |
| `landing_page` | ScopeRules :123 / WriteGuard :380 | `salesChannels.id IN own` | existing: all of its channels must be own; insert always passes this branch (`:383-385`), channels checked via `landing_page_sales_channel` |
| `landing_page_translation` | ScopeRules `LANDING_PAGE_CHILDREN` :131 / WriteGuard :380 | `landingPage.salesChannels.id IN own` | landing page wholly own or inserted now |
| `landing_page_sales_channel`, `landing_page_tag` | WriteGuard :83,380 (no read rule) | not filtered | as landing page; the channel in the payload/PK must be own |
| `promotion`, `promotion_sales_channel`, `PROMOTION_CHILDREN`, `promotion_discount_prices` | ScopeRules :124-127 | via `salesChannels.salesChannelId` | `PromotionGuardSubscriber` (other module) |
| `sales_channel_country/currency/language/payment_method/shipping_method/translation`, `theme_sales_channel` (`CHANNEL_MAPPINGS`) | ScopeRules :129 / WriteGuard :486 | `salesChannelId IN own` | channel in PK/payload must be own |
| `sales_channel_analytics` | ScopeRules :130 / WriteGuard :495 | `salesChannel.id IN own` | update: all channels using it must be own; insert not checked |
| `theme` | WriteGuard :504 (no read rule) | all themes visible | insert refused; update only if used by own channels only |
| `system_config`, `seo_url`, `seo_url_template`, `sales_channel_domain`, `product_export`, `sales_channel_file` (`CHANNEL_SETTINGS`) | ScopeRules :136, `systemConfig` :150 / WriteGuard :532-553 | own OR NULL channel (config additionally filtered by readable/hidden domains) | stored channel must be own (NULL refused); insert/payload channel must be own; `system_config` key must be editable (`ConfigRules`) |
| `rule`, `rule_condition` | WriteGuard `denyGlobal` :153 (no read rule) | all | `rule`: insert only; `rule_condition`: insert only for a rule inserted in the same write |
| `payment_method`, `shipping_method`, their translations, `swag_dynamic_access_*_rule` (`GLOBAL_RECORDS`) | WriteGuard :72,156 | all | refused always |
| `product_sorting` | WriteGuard :528 | all | refused always (insert too) |
| `acl_role_sales_channel`-mapping (`AclRoleSalesChannelDefinition::ENTITY_NAME`) | WriteGuard :284 | n/a | refused always |
| everything else (currency, country, language, tax, tag, customer_group, product_manufacturer, property_group, media, mail_template, number_range, flow, `order_tag`, `customer_tag`, `product_cross_selling_assigned_products`, ...) | none | unfiltered (`default => null`, `ScopeRules.php:140`) | unguarded; limited only by the role's ACL privileges |

## Comparison with docs

Compared `docs/architecture.md` ("How each entity belongs...", "Read filter", "Write guard") and `docs/extending.md` with the code.

| Doc claim | Code | Verdict |
|---|---|---|
| Read-filter table, `docs/architecture.md` "Read filter" | matches `ScopeRules.php:111-141` for all rows I checked | consistent |
| `product_review_summary`: `salesChannelId IN (...)` (architecture "Read filter") | `CHANNEL_SETTINGS` filter is `IN own OR IS NULL` (`ScopeRules.php:136-139`, constant `:81-89`) | doc imprecise: summaries without a channel are visible; write refuses them |
| Wishlists and wishlist products as in code | `services.php:108-115` | consistent |
| CHANGELOG 0.12.0: "password recovery links follow their customer (`SalesChannelColumnRule`)" | recovery links are handled in `ScopeRules.php:116` and `WriteGuardSubscriber.php:358`, not by `SalesChannelColumnRule` | changelog wording inaccurate [verified] |
| "Themes: sees all; configuration only when used by the user's channels only" | no read rule for `theme`; write `:504` (insert refused) | consistent |
| "Write guard" table: `WriteRuleSubscriber` covers "order details' details, wishlists, state transitions, newsletter recipients, other plugins' entities" | `services.php:96-115,155-158` | consistent |
| Doc presents `WriteRule` as the guard extension point, built-ins as "guards" | the largest guard (`WriteGuardSubscriber`, 629 lines) is not a rule and its entity lists are class constants, not extensible | doc does not say this; third-party plugins cannot add entities to it, only add rules |
| Limitations: "Commercial features, product cross-selling assigned products not checked" | confirmed: no rule for `product_cross_selling_assigned_products` yet `products.json` preset grants create/delete (`grep` of presets) | documented gap |
| Not in docs: `order_tag` (the orders preset grants `order_tag:create/update/delete`, `src/Resources/config/presets/orders.json`) and `customer_tag` | no read or write rule in `src` | undocumented gap, see Risks |
| `docs/extending.md:154-156` "changeable only when it belongs to the user's channels only" | `SalesChannelColumnRule` is single-channel; the "all channels" convention is implemented only in `WriteGuardSubscriber` (`owns()` :577) and `WriteScope::ownsAll` (unused by built-in rules) | consistent as convention |

## Dependencies
- Uses:
  - `Scope\ChannelScope` (who is restricted; every subscriber constructor: `services.php:92,122,134`) and `Scope\ScopeRules` constants (`WriteGuardSubscriber.php:10,320,486,541`, `OrderDescendantRule.php:5,80`, `DbalScopeLookup`) [verified].
  - `Category\CategoryTrees`, `Scope\ConfigRules` (`WriteGuardSubscriber` constructor `:95-100`), `Cms\CmsPages` (inside `ScopeRules`) [verified].
  - `Exception\ChannelScopeException`, `Entity\AclRoleSalesChannelDefinition` (`WriteGuardSubscriber.php:6-7`) [verified].
  - Shopware: `EntitySearchedEvent`, `BeforeEntityAggregationEvent`, `PreWriteValidationEvent`, DAL filters and `WriteCommand` classes, `Doctrine\DBAL\Connection` [verified: imports].
  - Commercial entities by name only (review summaries, `swag_dynamic_access_*`), no class dependency [verified: string names].
- Used by:
  - `Subscriber\CloneGuardSubscriber`, `ImitateCustomerGuardSubscriber`, `StateHistoryGuardSubscriber`, `ThemeGuardSubscriber` take `ScopeLookup` (`services.php:138,142,156,165`); `StateHistoryGuardSubscriber` implements `WriteRule` [verified].
  - `ChannelColumnLookup` and `Tags::*_RULE` are the public API for other plugins (`docs/extending.md:20-29`); in this repo only `services.php` registers rules [verified: `grep` for `ReadRule|WriteRule|SalesChannelColumnRule` in `src`]. Whether any rotfuchs custom plugin registers rules is unknown [inferred: not checked, outside this repo].
  - Tests: `tests/StaticScopeLookup.php` implements `ScopeLookup` [verified].
- Interactions only (other modules): presets grant the privileges the rules then constrain; `CmsGuardSubscriber` and `PromotionGuardSubscriber` cover cms and promotion writes that this module's read filters (via `ScopeRules`) already cover for reads.

## Patterns and conventions

- A typical change for a new simple entity: add one `SalesChannelColumnRule` (or `ParentChannelRule`) service with both tags in `services.php` (pattern at `:95-115`), add a unit test next to `tests/Unit/Rule/`, add the entity to the leak check sweep automatically (schema-driven, `tests/acceptance/leak-check.php:57-110`) [verified].
- A typical change for a built-in entity family: add the name to a `ScopeRules` constant, add a `match` arm in `ScopeRules::readFilter`, add a branch to `WriteGuardSubscriber::deny*`, extend `ScopeLookup` + `DbalScopeLookup` + `tests/StaticScopeLookup.php` (the 0.12.0 commit eb78dd0 touched exactly these) [verified: git].
- Read rules only narrow (AND); write rules return a reason string; the first denial per command is reported, the whole write fails (`ReadRule.php:9-14`, `WriteRuleSubscriber.php:40-49`).
- Write checks compare both the stored record (`lookup`) and the payload (new value) and accept records inserted in the same write (`WriteScope::insertOf`, `WriteGuardSubscriber::inserted` :179).
- "Shared = read-only": changeable only if every channel of the record is own (`owns`), visible if any (`sees`) (`WriteGuardSubscriber.php:577-604`).
- Ids are bytes in write commands and hex in filters/lookups; helpers `hex()` and `Uuid::fromBytesToHex` everywhere.
- Raw SQL in lookups with identifier whitelists (`DbalChannelColumnLookup.php:12`, `DbalScopeLookup.php:194-199,228-239`).
- Same mechanism as Shopware's `MediaVisibilityRestrictionSubscriber` (stated in `ReadFilterSubscriber.php:25`).
- Style: `declare(strict_types=1)`, `final` rule classes, PHPStan level 8 (`docs/testing.md`).

## Tests

- Rule and guard tests: `tests/Unit/Rule/{SalesChannelColumnRuleTest(5),ParentChannelRuleTest(2),OrderDescendantRuleTest(5),WriteRuleSubscriberTest(3)}.php`, `tests/Unit/Subscriber/ReadFilterSubscriberTest.php` (14 tests), `tests/Unit/Subscriber/WriteGuardSubscriberTest.php` (25 tests, 417 lines), `tests/Unit/Subscriber/CategoryWriteGuardTest.php`. Counts by `grep -c "function test"` [verified].
- Doubles: `tests/StaticScopeLookup.php`, `tests/Ids.php`, `tests/StaticRoleChannelLoader.php`; no database or kernel [verified: `docs/testing.md`].
- Run: `composer test` (= `phpunit --testsuite unit`, `composer.json:49`), `composer phpstan`; in a shop `php phpunit.phar --testsuite unit` (`docs/testing.md`). Not run here (no local PHP).
- Integration: `tests/acceptance/leak-check.php` sweeps every entity in the schema as a limited user through the Admin API and probes special endpoints; CI runs it against seed data `tests/ci/seed.php` (`docs/testing.md`) [verified: docs; CI workflow not read].
- Coverage impression [inferred]: good for orders, customers, products, categories, settings, landing pages; thin for `DbalScopeLookup` / `DbalChannelColumnLookup` (SQL never exercised by unit tests, only via the CI leak check), for write paths of Sync API, upserts and cascade deletes (no test mentions sync, upsert or cascade: `grep -i "sync\|upsert"` in `WriteGuardSubscriberTest.php` found nothing), and for `ReadFilterSubscriber` the association walk (`testToManyAssociationsAreFilteredToo`, `:138`) is the only association test. The leak check detects reads only; it does not test writes.

## Hotspots

Git history: 23 commits by one author in 2026-11-10..13 [verified: `git log`]. Files touched by commits, counted over the listed paths:

| File | Commits | Note |
|---|---|---|
| `src/Resources/config/services.php` | 11 | every new subscriber or rule |
| `src/Subscriber/WriteGuardSubscriber.php` | 10 | every new entity family adds a branch (products, categories, landing pages, settings, themes, rules, reviews) |
| `src/Scope/ScopeRules.php` | 10 | read-side twin of the above |
| `src/Write/ScopeLookup.php`, `src/Write/DbalScopeLookup.php` | 6 each | one lookup per new write check |
| `src/Subscriber/ReadFilterSubscriber.php` | 3 | last change 0.12.1 (group by primary key for records without `id`) |
| `src/Rule/*` | 1-2 each | created in 0.11.0 (extension points) and 0.12.0 (`OrderDescendantRule`, `ParentChannelRule`) |

Recently active: 0.12.0 "Security review" (eb78dd0, 2026-11-11) and 0.12.1 (4545b60, 2026-11-12) [verified]. A new entity usually changes `ScopeRules` + `WriteGuardSubscriber` + `ScopeLookup` + `DbalScopeLookup` + `StaticScopeLookup` + tests together [inferred from commit stats].

## Risks

Security coverage gaps (most important first):

1. `order_tag` is unguarded. The orders preset grants `order_tag:create/update/delete` (`src/Resources/config/presets/orders.json`), but no read or write rule mentions it. A direct write to the mapping (Sync API entity `order_tag`) with an `order_id` of another channel is not refused by any rule [inferred: depends on Shopware allowing mapping-entity writes via Sync API; nested writes through `order` are guarded because the parent gets a touch update, the same effect documented at `WriteGuardSubscriber.php:90-91`]. Same pattern for `customer_tag`, `newsletter_recipient_tag` if granted. Not listed in `docs/architecture.md` "Limitations". The leak check cannot see it (mapping tables are skipped as "not searchable", `leak-check.php:92-93`).
2. `product_cross_selling_assigned_products` is writable (preset grants create/delete in `products.json`) and unguarded; known and documented (`docs/architecture.md` Limitations).
3. Read side of mapping tables (`product_category`, `product_option`, `product_property`, `product_tag`, `product_custom_field_set`, `category_tag`, `landing_page_tag`, `landing_page_sales_channel`) is unfiltered; safe only if the Admin API cannot search mapping entities, which the plugin assumes (`ReadFilterSubscriberTest.php:211`, `leak-check.php:92`) [inferred, depends on Shopware]. Reading them through an association on a filtered parent is covered by the to-many association walk.
4. Many-to-one and one-to-one associations are never filtered (`ReadFilterSubscriber.php:133-134`): a record from another channel can be embedded where an own record references it. Accepted and documented (`docs/architecture.md` Limitations, "Many-to-one joins").
5. `EntityRepository::read` by ids without a search is not hooked; the reference checkout dispatches `EntitySearchedEvent` in `search`/`searchIds`/`aggregate` and its private `read` does not (`EntityRepository.php:209-250,241,293,307`). Internal code calling `search` with ids still gets the filter (`:241`). Code in other plugins that bypasses `search` (DBAL, indexers, system scope) is outside the module's reach by design [inferred, reference checkout].
6. Guard chain only covers entities listed: any entity not in the map above that a role has privileges for is writable across channels (see "everything else" row). Ownership of `shipping_method_price`, `shipping_method_tag`, `payment_method_*` aggregates, `rule_tag`, `custom_field_*`, `delivery_time` is not checked; only `shipping_method`, `payment_method` and their translations are in `GLOBAL_RECORDS` (`WriteGuardSubscriber.php:72-80`). Impact depends on presets granting those privileges (marketing preset grants `shipping_method:update` and `payment_method:update`, `shipping_method_price:read` only) [verified: grep of presets; impact inferred].
7. `landing_page` inserts pass unconditionally (`WriteGuardSubscriber.php:383-385`); a new landing page without an assignment is visible to nobody but unrestricted users. Probably harmless [inferred].
8. `sales_channel_analytics` inserts are not checked (`:495`, `!$insert`); `sales_channel.analytics_id` and other foreign-key payloads of an own channel update are not validated against ownership of the target [inferred].
9. `product_review` writes check the channel only, not that `product_id` points to a visible product (`:472-484`) [inferred].
10. Upserts, cascade deletes, versioned writes: the commands go through `PreWriteValidationEvent` in the reference checkout (`EntityWriteGateway.php:141`; `CascadeDeleteCommand extends DeleteCommand`) so upserts of existing records are `UpdateCommand`s checked against the stored record, and cascades are checked as deletes [inferred from reference checkout]. DB-level foreign-key cascades and `ON DELETE SET NULL` do not produce commands and are not checked; since the parent delete is checked this mostly matters for shared children (for example deleting an own customer who has orders in another channel) [inferred]. Version creation (`createVersion` clone) and its scope were not verified; the guard test comment at `WriteGuardSubscriberTest.php:112` mentions new order versions with inserts of order and items.
11. Refund positions: read goes via `orderLineItem.order.`, write checks the parent refund's order (`OrderDescendantRule.php:32,62-69`). A user could insert a refund position under an own refund that references a foreign order's line item (not checked), which they then cannot read [inferred, low impact].
12. `WriteScope::isOwn` on a 16-char value treats it as bytes (`:51`); safe for hex ids (32 chars) [verified].

Performance:

- Reads: for restricted users every search/aggregation/association criteria iterates all rules; cheap (`match` and `in_array`). The cost is the joins: `product` filter is an OR of two to-many joins (own and parent visibilities) and forces `GROUP BY id` (`ScopeRules.php:171-177`, `ReadFilterSubscriber.php:73-89`); `customer` filter is a 3-way OR including `orderCustomers.order.salesChannelId` (`:184-191`), acknowledged in `docs/architecture.md` ("Performance"); `cms_*` filters call `CmsPages::visibleIds()` and build an `EqualsAnyFilter` with a possibly large id list (`:132-135`). Unrestricted callers pay one `getScope()`/`getSource()` check per search (`ChannelScope.php:31-37`) [verified].
- Writes: no caching in `DbalScopeLookup` or `DbalChannelColumnLookup` (grep for cache/memo found nothing). Each command triggers its own SQL query (for example one `productChannels` per `product_price` command; `OrderDescendantRule` up to 6 parent lookups per command), so large Sync API batches by restricted users scale linearly with extra queries [verified: no cache; impact inferred].
- `ChannelScope` caches per user for the request and is reset via `kernel.reset` (`services.php:66-68`) [verified].

Consistency and fragility:

- Two parallel registries of entities: `ScopeRules` constants (read) and `WriteGuardSubscriber` constants/branches (write) must be kept in sync by hand; one unit test guards only product details (`ReadFilterSubscriberTest.php:209-216`). Other pairs (for example categories' children, landing page children) have no such test [verified].
- `WriteGuardSubscriber` is a 629-line if-chain with partly duplicated logic (`sees`/`owns`, hex conversion) next to the newer `WriteRule` API; two styles for the same job [verified].
- `DbalScopeLookup::parentOf` restricts names to `/^[a-z_]+$/` while `DbalChannelColumnLookup` allows digits (`DbalScopeLookup.php:196`, `DbalChannelColumnLookup.php:12`); `parentOf` ignores `version_id` ("any version counts", `ScopeLookup.php:84-87`) [verified].
- `OrderDescendantRule::orderChannel` passes `''` as version when the insert has no `order_version_id` (`:90`); relies on the DAL filling defaults into the payload, otherwise the insert is denied (fails closed) [inferred].
- Dead code: `WriteScope::ownsAll`, `WriteScope::salesChannelIds`, `WriteScope::context` and `commands()` have no users in `src` besides the class itself and the extension docs; `SalesChannelColumnRule::$allowAllChannels` is never set to true in `services.php` (only documented) [verified by grep of `src`; they are public API for other plugins so not necessarily dead].
- `ReadFilterSubscriber::MAX_DEPTH = 8` silently stops filtering deeper to-many associations (`:29,118`) [verified]; the 6-step limit in `OrderDescendantRule` fails closed (returns null -> denied).
- Behaviour depends on Shopware internals: event dispatch points, `Criteria` association API, `RepositorySearchDetector` and grouping of joined to-many filters (the 0.12.1 bug was exactly such an internal: "Unknown column 'product_translation.id'") [verified: CHANGELOG 0.12.1]; CI tests against 6.7 lowest/highest and trunk (`docs/testing.md`).

## Open questions

- Is the missing guard for `order_tag` / `customer_tag` / `product_cross_selling_assigned_products` a deliberate scope decision or an oversight? Who: plugin author (Moritz Naczenski, sole committer).
- Why is `product_review_summary` in `CHANNEL_SETTINGS` (NULL channel visible) while `docs/architecture.md` says plain `salesChannelId IN`? Who: plugin author.
- Why is `WriteGuardSubscriber` not migrated to `WriteRule` services, and is that planned? Who: plugin author.
- Why do refund positions read through the line item but write through the refund (`OrderDescendantRule.php:31-33` comment explains read only: "wrong storage name")? Who: plugin author.
- Are order versions (`createVersion`) and the Sync API `upsert` / cascade-delete paths exercised anywhere end to end? Who: plugin author; check the CI workflow `.github/workflows/tests.yml` (not read here) and run the leak check with a write probe.
- Does any rotfuchs custom plugin (for example MnMultiDelivery, MnTieredPromotions) register read or write rules or write entities a limited user could touch? Who: rotfuchs operator; check the other plugins in `rotfuchs-deploy/`.
- Do rotfuchs production role presets actually grant the privileges named in the risks (order_tag, shipping_method:update)? Who: rotfuchs operator, via the live `acl_role` rows.
- Is the reference checkout's DAL behaviour (events, `CascadeDeleteCommand`, mapping-entity writes via Sync API) identical in the production Shopware 6.7.16.0? Who: whoever can read `vendor/shopware/core` on the server.

## Verified by /onboard (2026-11-17)
S2 [verified]: as the CI user with all six presets, tagging the foreign order worked via `POST /api/_action/sync` (order_tag upsert, HTTP 200) and `POST /api/order-tag` (HTTP 204); the admin then saw the tag on the foreign order. The `order_tag` gap above is confirmed.

0.12.2: S2 fixed. `WriteGuardSubscriber` checks `order_tag` against the order's channel and `customer_tag` against the customer (primary key, inserts and deletes); verified in the test shop (Sync API and entity API refused with `MN_CHANNEL_SCOPE__WRITE_DENIED`) and by 2 new unit tests [verified].
