# Module: ownership-sources
_Path: src/Category, src/Cms, src/Promotion, src/Totals, src/Dashboard, src/Subscriber/ChannelTotalsSubscriber.php, src/Migration/Migration1763900000CmsPageSalesChannel.php (wiring: src/Resources/config/services.php)_

Marks: [verified] = read in code/docs; [inferred] = conclusion from code, not run. No PHP available locally, nothing was executed. `git log` works (the task environment claimed otherwise; it does).

## Responsibility
Answers "which sales channels does this record belong to?" for entities that have no (single) sales channel column, and recomputes cross-channel totals for a limited user. Categories derive from the channels' navigation/footer/service trees, CMS layouts from what uses them, promotions from `promotion_sales_channel`; customer/product totals and the dashboard turnover are re-queried per channel. [verified: src/Category/CategoryTrees.php:10-21, src/Cms/CmsPages.php:8-19, src/Totals/ChannelTotals.php:5-9, src/Dashboard/ScopedOrderAmountService.php:16-22]
The module only supplies facts and answers; the decisions (filters, write guards) live in other modules that consume it. [verified: consumers below]

## Entry points and interfaces
Each derivation is a pure-logic class over a storage interface, with a DBAL implementation (Static* test doubles exist in tests/).

| Piece | File | Notes |
|---|---|---|
| `CategoryTrees` (logic) | src/Category/CategoryTrees.php | `readFilter()` :31, `owners()` :56, `isEditable()` :70, `isVisible()` :83, `hasForeignEntriesBelow()` :110, `entryPointsFor()` :133 [verified] |
| `CategoryTreeSource` / `DbalCategoryTreeSource` | src/Category/CategoryTreeSource.php:9, DbalCategoryTreeSource.php:14 | `entryPoints()` (channel => nav/footer/service category ids), `paths(ids)` [verified] |
| `CmsPages` (logic) | src/Cms/CmsPages.php | `channels()` :36, `visibleIds()` :56, `isVisible()` :76, `isEditable()` :86, `pageOf()` :100, `addOwners()` :108 [verified] |
| `CmsUsageSource` / `DbalCmsUsageSource` | src/Cms/CmsUsageSource.php:8, DbalCmsUsageSource.php:13 | `pages()`, `pageOf(entity, pk)` for section/block/slot, `addOwners()` (writes) [verified] |
| `PromotionSource` / `DbalPromotionSource` | src/Promotion/PromotionSource.php:8, DbalPromotionSource.php:11 | `channels()`, `promotionOf()`, `assignmentChannel()`; no logic class, guard uses it directly [verified: src/Subscriber/PromotionGuardSubscriber.php:185-187] |
| `ChannelTotals` / `DbalChannelTotals` | src/Totals/ChannelTotals.php:10, DbalChannelTotals.php:19 | `customers()` :25, `ratings()` :92, `sales()` :124 [verified] |
| `ChannelTotalsSubscriber` | src/Subscriber/ChannelTotalsSubscriber.php:23 | listens `customer.loaded`, `customer.partial_loaded`, `product.loaded`, `product.partial_loaded` (:37-40) [verified] |
| `ScopedOrderAmountService` | src/Dashboard/ScopedOrderAmountService.php:24 | decorates `Shopware\Administration\Dashboard\OrderAmountService` (services.php:187-195); `load()` :36 [verified] |

Extension points: the source interfaces are aliased (services.php:72, 77, 126, 146), so a replacement implementation can be swapped in. [verified] docs/extending.md was not read; whether these aliases are documented as public API is open (see docs/compatibility.md:20-22). [inferred]

Consumers (the "used by" side):
- `CategoryTrees`: ReadFilter via src/Scope/ScopeRules.php:121 (`entity === 'category'`); write guard src/Subscriber/WriteGuardSubscriber.php:230, :298; `CmsPages` (constructor). [verified]
- `CmsPages`: ScopeRules.php:134 (visibleIds for cms_page read filter); src/Subscriber/CmsGuardSubscriber.php:91 (addOwners), :152 (isEditable); src/Subscriber/CloneGuardSubscriber.php:84 (addOwners after clone); src/Controller/ChannelScopeController.php:67-68 (editor info for the admin). [verified]
- `PromotionSource`: src/Subscriber/PromotionGuardSubscriber.php. [verified]
- `ChannelScope` is used by the subscriber and the dashboard service to find the user's channels (null = unrestricted). [verified: ChannelTotalsSubscriber.php:49-52, ScopedOrderAmountService.php:38-41]

## Data model
- Only own table: `mn_cms_page_sales_channel (cms_page_id, cms_page_version_id, sales_channel_id)`, PK on all three, FKs to `cms_page(id, version_id)` and `sales_channel(id)` both ON DELETE CASCADE. Created by `Migration1763900000CmsPageSalesChannel` (src/Migration/Migration1763900000CmsPageSalesChannel.php:21-35, `CREATE TABLE IF NOT EXISTS`, no `updateDestructive`). [verified] No DAL entity definition for it; only raw SQL touches it (DbalCmsUsageSource.php:81, :119). [verified by grep of services.php for definitions: only AclRoleSalesChannelDefinition is registered near there; full grep not done] [inferred]
- Source tables read (all live version): `sales_channel` (navigation/footer/service category ids), `category` (`path`, `cms_page_id`), `cms_page` (`locked`), `product` + `product_visibility`, `landing_page` + `landing_page_sales_channel`, `system_config` (`core.cms.default_%`), `cms_section/block/slot`, `promotion*` tables, `order`, `order_customer`, `order_line_item`, `order_transaction`, `product_review`, `state_machine_state`, `currency`. [verified: the Dbal* files cited above]
- Derived (not stored): category ownership, CMS ownership except the owner table, totals. No caching table, no stored per-channel totals. [verified]

## Dependencies
- Uses: Doctrine DBAL `Connection` everywhere; Shopware core classes (`Defaults`, `Uuid`, `OrderStates`, `LineItem`, DAL filters in CategoryTrees.php:5-8); `Shopware\Administration\Dashboard\OrderAmountService`, `CashRounding` (ScopedOrderAmountService.php:8-9); `ChannelScope` (src/Scope) in subscriber and dashboard. [verified]
- Internal: `CmsPages` -> `CategoryTrees` (CmsPages.php:27, services.php:78-80). [verified]
- Used by: Scope (ScopeRules), Subscriber guards (Write, Cms, Clone, Promotion), Controller (ChannelScopeController). See consumer list above. [verified]
- Requires `shopware/administration` for the dashboard decorator (composer.json:14-15). [verified] If the Administration bundle is absent, services.php:48/187 would reference a missing class. [inferred]

## Patterns and conventions
- Interface + Dbal implementation + alias in services.php; unit tests use Static* doubles in tests/. A typical change: adjust the SQL in the Dbal* class, extend the interface and the Static* double, extend the unit test of the logic class. [verified: tests/StaticCategoryTreeSource.php etc.; services.php:69-80, 125-126, 145-146]
- Ids are lower-case hex strings throughout; the SQL does `LOWER(HEX(..))`, bytes via `Uuid::fromHexToBytes*`. [verified]
- Only the live version is considered (`Defaults::LIVE_VERSION`) in every query except `DbalPromotionSource` (no version column on promotion tables, not applicable) and the `product.id` joins in totals. [verified]
- Request-scoped memoisation, cleared by the `kernel.reset` tag: `DbalCategoryTreeSource` (services.php:69-71), `DbalCmsUsageSource` (74-76), `CmsPages` (78-80). `CategoryTrees`, `DbalPromotionSource`, `DbalChannelTotals` have no cache. [verified]
- Totals queries are copies of Shopware's own (comment at DbalChannelTotals.php:12-16) with an added sales channel condition; the dashboard query is a copy of `OrderAmountService::load()` plus `sales_channel_id IN (:salesChannelIds)` (ScopedOrderAmountService.php:60). Compared against /Users/M.Naczenski/Documents/product-analytics/shopware/src/Administration/Dashboard/OrderAmountService.php (some Shopware checkout, version not checked): the copy matches apart from the added condition. [verified]
- Rules: category visible = in own trees or ancestor of own entry (read-only); editable = every owner channel is in the user's set; a category in no tree is neither (CategoryTrees.php:10-21, :70-78). CMS editable = not locked, has owners, all owners within the user's set (CmsPages.php:86-95). [verified]

## Tests
- Unit tests (PHPUnit 11): tests/Unit/Category/CategoryTreesTest.php, tests/Unit/Cms/CmsPagesTest.php, tests/Unit/Cms/CmsGuardSubscriberTest.php, tests/Unit/Subscriber/ChannelTotalsSubscriberTest.php, PromotionGuardSubscriberTest.php, CategoryWriteGuardTest.php, CloneGuardSubscriberTest.php, ReadFilterSubscriberTest.php, tests/Unit/Controller/ChannelScopeControllerTest.php. Run: `composer test` = `phpunit --testsuite unit` (composer.json:49). Not run here (no PHP). [verified]
- No tests exist for the SQL: no test file matches Dbal*/Totals/Dashboard (`find tests` for total|dashboard|Dbal|Scoped returned only ChannelTotalsSubscriberTest.php). `DbalCategoryTreeSource`, `DbalCmsUsageSource`, `DbalPromotionSource`, `DbalChannelTotals`, `ScopedOrderAmountService`, and the migration are untested by unit tests. [verified]
- tests/acceptance/leak-check.php and CI "installed shop" jobs exist (docs/compatibility.md:33); I did not read leak-check.php in detail and don't know whether it covers these derivations. [verified that it exists; coverage unknown]
- Impression: logic classes well covered, storage/SQL layer covered only indirectly if at all. [inferred]

## Hotspots
`git log` over these paths shows only 6 commits, all 2026-11-10 to 2026-11-11 (versions 0.2.0 to 0.11.0): 05da5a1 (0.11.0), 1ef8b41 (0.10.0), d36bba6 (0.7.0), bc05f4e (0.5.0), b95a007 (0.4.0), fbb14d0 (0.2.0). Files touched twice: src/Totals/DbalChannelTotals.php and src/Subscriber/ChannelTotalsSubscriber.php; everything else once. [verified] Later commits (eb78dd0 0.12.0, 4545b60 0.12.1, 5e38952) did not touch these paths. [verified] History is thin, so "hotspot" is weak evidence; the busiest consumers are WriteGuardSubscriber and ScopeRules (not mapped here). [inferred]
The most change-prone spot by design is `DbalCmsUsageSource::pages()` (src/Cms/DbalCmsUsageSource.php:22-88): every new way a layout can be used (new Shopware entity with `cms_page_id`) needs another query here. [inferred]

## Risks
Correctness
1. Ownership of CMS layouts is incomplete if Shopware adds new usages. Covered: categories, products/variants, landing pages, `core.cms.default_*`, owner table (DbalCmsUsageSource.php:40-85). Not covered by code: layouts referenced from elsewhere (e.g. other plugins, themes, app CMS blocks, product translation overrides). A layout used only there is "unused" and hidden/uneditable for limited users. [inferred]
2. Product usage requires a visibility row (`JOIN product_visibility`, DbalCmsUsageSource.php:52). A product with a layout but no visibility gives the layout no channel. [verified from SQL; effect inferred]
3. Variants: the SQL matches visibilities of the product or its parent (:52) and layout of variant or parent (:49); category `cms_page_id` is read raw without inheritance rules. [verified]
4. `core.cms.default_%` rows with NULL channel are expanded to all channels (:70-78), so a layout set as global default is visible/ shared with every channel, hence never editable by a user limited to a subset (isEditable needs all owners within user set, CmsPages.php:94). Docs say the same ("all channels, or the one they are set for", docs/architecture.md:170). [verified]
5. The LIKE pattern is `core.cms.default\_%` inside a PHP double-quoted string with `\\_` (:72), producing `\_`; correct if the connection does not use NO_BACKSLASH_ESCAPES. [inferred]
6. A layout in several channels (shared) cannot be edited by any single-channel user, and the owner row is added only at creation, so ownership never shrinks: `mn_cms_page_sales_channel` rows persist after the layout is later used elsewhere, and are not removed when a user loses a channel (FK cascades only on channel/page delete). [verified for no delete code in this module; inferred effect]
7. `addOwners` for clones: CloneGuardSubscriber.php:84 records owners after the response, outside the write's transaction (unlike CmsGuardSubscriber.php:75-92, which says "Runs in the write's transaction"). A failure between clone commit and the insert leaves an ownerless, unused clone hidden from the creator. [verified order; impact inferred]
8. Category ownership: a category belongs to every channel whose entry is the category or an ancestor (CategoryTrees.php:149-160), based on the stored `path`. If `path` is stale/null for a non-root category it is treated as a root (DbalCategoryTreeSource.php:58). Entry categories shared by two channels make everything below read-only for both (isEditable needs all owners). [verified; staleness effect inferred]
9. Category readFilter builds an `EqualsAnyFilter` with all ancestor ids plus one `ContainsFilter(path, '|entry|')` per entry (CategoryTrees.php:43-48): a LIKE `%|id|%` scan of `category`, one per entry (up to 3 per channel), not index-assisted. [verified; performance inferred]
10. `CategoryTrees::isVisible()` and `owners()` return false/null for categories not yet stored; the write guard compensates with `$newCategories` (WriteGuardSubscriber.php:230). Behaviour for the same-request creation of a channel's entry category change is not examined. [verified existence; rest unexamined]
11. Promotions: `DbalPromotionSource::channels()` returns `[]` for a promotion with no assignment (:27-30); whether `[]` is editable is decided in PromotionGuardSubscriber (not read in full). A promotion without assignments is thus ownerless. [inferred]
12. Totals subscriber: `hasAny()` returns true for every `CustomerEntity`/`ProductEntity` regardless of requested fields (ChannelTotalsSubscriber.php:109-111), so every `product.loaded`/`customer.loaded` for a limited user triggers 2 (customers) or 2 (ratings+sales) extra queries, including nested loads (products as association of order line items, etc.). [verified; cost inferred]
13. Filtering, sorting and aggregations on the totals still use the global stored values, so a limited user can infer other channels' turnover by sorting/filtering `orderCount`, `orderTotalAmount`, `sales`, `ratingAverage`. Acknowledged in code (ChannelTotalsSubscriber.php:21) and docs (docs/architecture.md:194, 232). [verified]
14. `customers()` counts only completed orders (`technical_name = completed`) and sums `amount_total / currency_factor` rounded to 2; `sales()` counts all non-cancelled orders; mixed rules mirror Shopware's own subscribers per the docblock but equality with Shopware was not checked against its source. [verified code; parity unverified]
15. `ratings()` uses `product.id IN (:ids) OR product.parent_id IN (:ids)` (DbalChannelTotals.php:104), an OR that can prevent index use; also joins `product` without a `product_review` version. [verified; performance inferred]
16. Dashboard: `ScopedOrderAmountService` extends the `@internal` `OrderAmountService` (Shopware marks it `@internal` and `readonly`) and duplicates its SQL (ScopedOrderAmountService.php:21-24, :43-77). A change to Shopware's query (new filters, new states) silently diverges for limited users. The constructor passes dependencies twice (inner + parent). Also `$paid` join on `order_transaction` can duplicate rows when an order has several paid transactions (same as Shopware's original). Other dashboard endpoints (e.g. statistics on orders count/today) are not handled in this module. [verified; others inferred]
17. A user with an empty channel list: dashboard produces `IN ()` with an empty binary list. `salesChannelIds` returning `[]` (not null) is not guarded in `ScopedOrderAmountService::load()` (:38-41), unlike the subscriber's per-method early return in `DbalChannelTotals` (:27, :94, :126). Behaviour of DBAL with empty array is a SQL error or zero rows, not verified. [inferred]

Performance and caching
18. `DbalCmsUsageSource::pages()` loads every CMS page and runs 5 full-table queries once per request (cached afterwards, :22-87). Product join (:48-53) scans all products with a layout, and `visibleIds()` loops over all pages calling `channels()` which calls `CategoryTrees::owners()` per category, which calls `source->paths([$id])` (CategoryTrees.php:59) and each uncached category costs one SQL query. N+1 pattern for shops with many categories using layouts, per distinct user channel set per request. [verified; scale inferred]
19. Cache invalidation: only per-request (kernel.reset). `DbalCategoryTreeSource` caches `entryPoints` and `paths` and is never reset by writes in the same request, so a request that moves categories (or changes a channel's entry category) and then evaluates guards sees the old tree. `DbalCmsUsageSource` is reset only by its own `addOwners()` (:123), not when categories/products/landing pages change; `CmsPages::visible` likewise (CmsPages.php:111). Cross-request staleness cannot occur (no persistent cache); in a long-running worker the `kernel.reset` tag covers it only if the worker triggers reset. [verified; consequences inferred]
20. `CmsPages::visibleIds()` memoises by `implode(',', $ids)` with unsorted ids (CmsPages.php:58); same set in different order caches twice. Harmless. [verified]
21. No persistent cache or invalidation exists, so every admin request of a limited user for categories/layouts recomputes from SQL. `ScopeRules.php:134` shows the CMS list read filter turns into an `id IN (...)` list of all visible layout ids; with thousands of layouts that gets large. [verified; scale inferred]

Dead code / inconsistencies
22. `CategoryTrees::entryPointsFor()` (:133) and `isVisible()` usage: only grep of `isEditable|visibleIds|owners` outside the module shown above; `entryPointsFor` and `hasForeignEntriesBelow` were not found in my grep of consumers (grep pattern did not include them), so usage unconfirmed. [inferred: possibly used by ChannelScopeController or admin]
23. `CmsUsageSource::addOwners()` is a write method on an interface called "Source" that otherwise holds read-only facts; `DbalCmsUsageSource` is also the only Dbal source that writes. [verified]
24. `DbalPromotionSource` has no `ResetInterface`/cache (each call hits the DB) while the other sources memoise: inconsistent but harmless. [verified]
25. The test double convention (`Static*`) exists for category/cms/promotion sources but not for `ChannelTotals`; ChannelTotalsSubscriberTest.php presumably uses an anonymous class. [inferred]

## Open questions
- Why is the owner table raw-SQL only rather than a DAL entity (so cascade/association features and `EntityDefinition` checks are skipped)? Not stated in code. 
- Why are clones' owners recorded after the response (CloneGuardSubscriber.php:84) rather than in the write transaction like CmsGuardSubscriber.php:91?
- Why are categories shared by two channels read-only for both rather than editable by users holding both channels? (The code's rule is "all owners within the user's set"; reason for strictness is not recorded.)
- Was the decision not to cache across requests deliberate (correctness) or just not needed yet at POC scale? No performance or scale target is documented.
- What happens for a limited user with an empty channel list on the dashboard (ScopedOrderAmountService.php:38-60)?
- Are `entryPointsFor()` and `hasForeignEntriesBelow()` used outside the module? Not confirmed by me.
- Why does the product usage query require `product_visibility` rows instead of treating products without visibility as unowned by design? Intent unknown.
- Which Shopware version was the dashboard copy taken from? The comparison above used an unrelated local checkout, not the rotfuchs 6.7.16 vendor.
