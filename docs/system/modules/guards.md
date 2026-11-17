# Module: guards (guards around the data layer)
_Path: src/Subscriber/{AdminSearch,ChannelParameter,Clone,ImitateCustomer,StateHistory,SystemConfig,Theme,Cms,Promotion}GuardSubscriber.php, src/Scope/ConfigRules.php, src/Scope/SettingsDomains.php, src/Resources/config/services.php_

Conventions: `[verified]` = read in this repo's code or git. `[inferred]` = from memory of Shopware core or reasoning; the plugin has no `vendor/` in the checkout, so no core route name or priority could be checked against core. All paths are relative to the plugin root. Git works here (`git log` used). Plugin version 0.12.1 (`CHANGELOG.md`).

## Responsibility
Nine subscribers close the paths where a limited admin user (role bound to sales channels) would get around the read filter and the write guard: raw-SQL endpoints, endpoints that run in a storefront or system context, and entities (layouts, promotions) that need cross-record checks. Each refuses the request, or adds a filter or validation constraint, only for limited users. `ChannelScope::salesChannelIds()` returns `null` for everyone else, and every guard returns early on `null` [verified: src/Scope/ChannelScope.php:30-52; first lines of each guard]. `ConfigRules`/`SettingsDomains` hold the allow/deny lists for system config, shared by the system-config guard, the write guard and the read filter [verified].

Who counts as "limited" [verified: ChannelScope.php:30-52]: system scope, non-`AdminApiSource`, `isAdmin()`, no user id (integrations), no roles, or any role without channels all give `null` (unrestricted). So **integrations are never guarded** (also noted in docs/architecture.md "Limitations").

## Entry points and interfaces
All route guards listen on `KernelEvents::CONTROLLER` with priority `KernelListenerPriorities::KERNEL_CONTROLLER_EVENT_SCOPE_VALIDATE_POST` [verified, e.g. AdminSearchGuardSubscriber.php:38]. They match on the `_route` attribute name [verified]. Whether that priority runs after Shopware's ACL privilege check is [inferred] from the constant's name. Refusals are `ChannelScopeException` (HTTP 403; codes `MN_CHANNEL_SCOPE__ROUTE_DENIED`, `__SETTINGS_DENIED`, `__WRITE_DENIED`) [verified: src/Exception/ChannelScopeException.php:11-40]. All are tagged `kernel.event_subscriber` in src/Resources/config/services.php (lines 117-170) [verified].

### Guarded routes and events (what is allowed or denied for a limited user)

| Guard (file:line) | Hook / route name | Allows | Denies |
|---|---|---|---|
| AdminSearchGuardSubscriber (:29, :42-53) | controller, `api.admin.es-search` (`POST /api/_admin/es-search`) | nothing (route refused outright) | every limited user; message points to `/api/_admin/search` [verified] |
| ChannelParameterGuardSubscriber (:31-38, :58-87) | controller, routes below | salesChannelId in own channels (compared lowercase) | any other or missing id |
| - `api.admin.product-stream-preview` | `salesChannelId` in route attributes | | |
| - `api.action.sales_channel_file.list/detail/preview` | attributes | | |
| - `api.action.preview_search.search` (Commercial Advanced Search) | query string | | |
| - `api.proxy.switch-customer` | body (form or JSON, max depth 8, :89-112) | own channel and a `customerId` the user can see (`customer.repository->searchIds` with user context, :83) | foreign channel, invalid or invisible customer |
| CloneGuardSubscriber (:33, :51-72, :74-86) | controller + response, `api.clone` (`POST /api/_action/clone/{entity}/{id}`) | source visible through the repository; for `product`, only if all its visibility channels are within the user's channels | invisible source; product with no, null or foreign channels (:68). On a 200 response for `cms_page`, the new page gets the user's channels as owners (`CmsPages::addOwners`, :77-85) |
| ImitateCustomerGuardSubscriber (:23, :36-66) | validation event `framework.validation.impersonation.generate-token` (`POST /api/_proxy/generate-imitate-customer-token`) | own `salesChannelId`; `customerId` registered in or bound to an own channel (`ScopeLookup::customerChannels`) | foreign channel; customer who only ordered in own channel (see docblock :14-19) |
| StateHistoryGuardSubscriber (:46-66, :92-107) | `EntitySearchedEvent`, `BeforeEntityAggregationEvent` on `state_machine_history`; also a `WriteRule` (:68-90, tag `mn_channel_scope.write_rule`, services.php:155-158) | search: `referencedId` narrowed to ids of own orders, deliveries and transactions (`ScopeLookup::ownStateRecords`); write: INSERT only, for own order records | search without `referencedId` filter finds nothing (filter on `00..0`, :106); OR and NOT groups are ignored when collecting ids (:109-125); update and delete always denied (:73) |
| SystemConfigGuardSubscriber (:28-30, :48-74) | controller: `api.action.core.system-config.value` (read), `api.action.core.save.system-config`, `api.action.core.save.system-config.batch` | read: `domain` readable (`ConfigRules::isReadable`) and `salesChannelId` empty/null (all channels) or own; save: channel set and own, every key `isEditable`; batch: same per channel key, empty channel blocks skipped (:117-130) | hidden domains, unreadable domains (message "only available to unrestricted users"); save for "all channels" (`null`, empty, "null") |
| ThemeGuardSubscriber (:28-29, :44-70) | controller: `api.action.theme.assign`, `api.action.theme.update`, `api.action.theme.reset` | assign: own `salesChannelId`; update and reset: theme used only by own channels (`ScopeLookup::themeChannels`) | foreign channel; theme with no or additional channels; invalid uuid |
| CmsGuardSubscriber (:57-77, :100) | `PreWriteValidationEvent` + `PostWriteValidationEvent` (not routes) | edit `cms_page`, sections, blocks, slots, translations if `CmsPages::isEditable` (not locked, only own channels); new pages always; category, product, landing_page may only reference visible layouts (`cms_page_id`, :69-76) | edit of shared or locked layouts; re-parenting a part into a foreign layout. Post-write records the owners of new live `cms_page` rows (:79-96) |
| PromotionGuardSubscriber (:49-55, :57-90, :97-154) | `PreWriteValidationEvent` + controller `api.action.promotion.codes.replace-individual`, `...add-individual` | promotions assigned only to own channels, including children (discounts, prices, rules, set groups, individual codes, cart, order and persona rules, persona customers, translations); new promotion with at least one own `promotion_sales_channel` in the same write; code routes for editable promotions (`promotionId` from request body, :87) | shared promotions; assignment to foreign channels; new promotion without channel |

[verified] for the code behaviour in all rows. The `entity` argument of `api.clone` has `-` mapped to `_` (CloneGuardSubscriber.php:104).

Extension point: `SettingsDomains` services tagged `Tags::SETTINGS_DOMAINS` (`mn_channel_scope.settings_domains`) are collected by `ConfigRules` through `tagged_iterator` (services.php:82; src/Scope/SettingsDomains.php:5-14, 23-28) [verified]. `StateHistoryGuardSubscriber` is also a write rule tag (services.php:158) [verified].

### ConfigRules / SettingsDomains
- Shopware defaults (SettingsDomains.php:35-60) [verified]: editable `core.basicInformation`, `core.cart`, `core.loginRegistration`, `core.newsletter`, `core.sitemap`; readable `core`, `storefront`; hidden `core.app`, `core.frw`, `core.logging`, `core.mailerSettings`, `core.mediaAiTag`, `core.services`, `core.store`, `core.update`, `core.usageData`, `core.userPermission`, `core.webhook`.
- Matching: exact key or `domain.` prefix (ConfigRules.php:73-82). Editable domains are also readable (:36). Hidden wins over readable and editable (:46, :51) [verified].
- Users of ConfigRules besides the route guard: the read filter (src/Scope/ScopeRules.php:146-160, PrefixFilter on `configurationKey`) and the write guard (src/Subscriber/WriteGuardSubscriber.php:536) [verified]. The docs say the same ("the read filter and write guard apply the same rules to the entity API").

## Data model
The guards own no tables and no migrations [verified: no migration directory use in these files]. They read through `ScopeLookup` (`src/Write/ScopeLookup.php`, `DbalScopeLookup`), `CmsPages` (table `mn_cms_page_sales_channel`, owners, written by CmsGuard post-write and CloneGuard response), `PromotionSource` (`DbalPromotionSource`, `promotion_sales_channel`) and the role table `mn_acl_role_sales_channel` via `ChannelScope` [verified by docs/architecture.md and constructor args in services.php:64-80, 131-149; table definitions belong to other modules, not read here].

## Dependencies
- Uses [verified from constructors and services.php]: `Scope\ChannelScope` (all), `Write\ScopeLookup` (clone, imitate, state history, theme), `Cms\CmsPages` (cms, clone), `Promotion\PromotionSource` (promotion), `Scope\ConfigRules` (system config), `DefinitionInstanceRegistry` (clone), `customer.repository` (channel parameter), `Exception\ChannelScopeException`, `Rule\WriteRule`/`WriteScope` (state history). Core: `PreWriteValidationEvent`, `EntitySearchedEvent`, `BuildValidationEvent`, `KernelListenerPriorities`.
- Used by: `ConfigRules` is injected into `ScopeRules` (services.php:84-86) and `WriteGuardSubscriber` (:133-135) [verified]. The write rule subscriber picks up `StateHistoryGuardSubscriber` through the tag [verified]. The administration code, presets and docs/extending.md refer to `SettingsDomains` [inferred for admin].

## Patterns and conventions
- A typical new guard [verified from pattern in 9 files]: a class with `public const ROUTE = '<core route name>'`, `onController` that returns if `_route` differs or `salesChannelIds($context) === null`, then throws `ChannelScopeException::routeDenied|writeDenied|settingsDenied`. Register in services.php with `->tag('kernel.event_subscriber')`, add a unit test under `tests/Unit/Subscriber/`, add a row to docs/architecture.md "Paths around the data layer", and a leak-check probe if a route takes an id (tests/acceptance/leak-check.php).
- Entity-write guards (Cms, Promotion) use `PreWriteValidationEvent` and add exceptions to the event rather than throwing; they resolve new-in-this-write records first (`inserted()`), so nested writes with new parents pass [verified: CmsGuardSubscriber.php:148-165, PromotionGuardSubscriber.php:187-212].
- Ids are lowercased hex before comparison [verified].
- Static `ROUTES`-style const maps (ChannelParameterGuard) make adding a route one line [verified :31-38].

## Tests
- Unit tests in `tests/Unit/Subscriber/*GuardSubscriberTest.php`, `tests/Unit/Cms/CmsGuardSubscriberTest.php`, `tests/Unit/Scope/ConfigRulesTest.php`, with static fakes `tests/Static*.php` [verified]. Test method counts (grep): AdminSearch 2, ChannelParameter 4, Clone 4, Imitate 3, StateHistory 4, SystemConfig 5, Theme 3, Cms 7, Promotion 6, ConfigRules 2 [verified]. Counts are small per guard; coverage impression is "happy path and main denials", not branch-exhaustive [inferred].
- Run: `composer install`, `composer test` (PHPUnit), `composer phpstan`, `composer test:js` (docs/testing.md:16-19) [verified]; I did not run them (no local PHP).
- Acceptance: `tests/acceptance/leak-check.php` logs in as a limited user, sweeps entities and probes routes with a foreign channel/customer; seeded in CI (`tests/ci/seed.php`) [verified]. Admin-side route guards are only covered through that script, and only for the probes it contains [inferred, not read in full].

## Hotspots
`git log --name-only` (22 commits, 2026-11-10 to 2026-11-13) [verified]:
- src/Resources/config/services.php: 11 commits (touched by almost every release).
- src/Subscriber/WriteGuardSubscriber.php: 10 (outside this module but closely coupled; 629 lines).
- src/Scope/ConfigRules.php: 3; SystemConfigGuardSubscriber.php and StateHistoryGuardSubscriber.php: 2 each; all other guards: 1 each (all added in the span 0.6.0 to 0.12.0: Imitate/Clone/Promotion/Cms/Theme/AdminSearch in 0.6-0.11.1, ChannelParameter and StateHistory in 0.12.0 = commit eb78dd0 "Security review").
- The project is two days old in git terms, so recency shows feature order, not instability [inferred].

## Risks
Verified code findings:
1. **System config read can expose hidden keys through a parent domain.** `denyRead` only tests the `domain` query parameter (SystemConfigGuardSubscriber.php:81). `isReadable('core')` is true: `core` is readable and no hidden entry is a prefix-match of `core` (ConfigRules.php:73-82, SettingsDomains.php:45-57). Unit tests only try `core.mailerSettings`, `core.store`, `core.cart`, `storefront` (SystemConfigGuardSubscriberTest.php:23-35); no test with `domain=core`. If core's `getDomain('core')` returns all `core.%` keys, which I believe it does [inferred], a limited user reads mailer credentials, store license keys, etc. over `GET /api/_action/system-config?domain=core`. The entity-API path is protected (NotFilter on hidden prefixes, ScopeRules.php:152-160) [verified]; the route path is not filtered on the response [verified: no ResponseEvent hook]. Worth a manual test first.
2. **Clone guard checks visibility, not what the copy gets.** Only `product` and `cms_page` have extra logic (CloneGuardSubscriber.php:66-71, 77). Cloning other visible entities (promotion shared with other channels is visible if one own channel is assigned; category; landing page; rule, payment or shipping method) runs in system scope per the class docblock (:22-25) and copies their links; the write guard does not see it [verified for the docblock]. The effect (a copy assigned to other channels, or a global record created by a user who may not create it) is [inferred].
3. **Hard-coded core route names and event name.** A renamed route in a Shopware release makes a guard a silent no-op, with no failing test (tests use the constants) [verified: names are literals; compatibility check only covers admin components, CHANGELOG 0.11.3]. [inferred] risk for 6.7.x updates; `api.action.preview_search.search` is a Commercial route.
4. **ChannelParameterGuard ignores a missing context.** If `ATTRIBUTE_CONTEXT_OBJECT` is not a `Context`, it returns without denying (:68-70), and the same pattern is in all route guards. Fine if the attribute is always set after authentication [inferred].
5. **StateHistory read filter is bypassable by shape?** Only top-level AND `referencedId` filters narrow; any other shape ends up with the `NONE` filter (:99-106), so it fails closed [verified]. The aggregation path is covered (:61-66). Associations loaded from other entities (e.g. `order.stateMachineHistories` if one existed) are not covered [inferred].
6. `ImitateCustomerGuardSubscriber`: if `$value` for salesChannelId is a non-string it adds a violation, good; the constraint is added only when the core definition has these fields [verified :44-65].
7. docs vs code: docs/architecture.md says "Admin search (`POST /api/_admin/search`) ... none needed" and "Store API proxy ... none needed" (:158). The first is consistent with AdminSearchGuard's docblock; the second is a **claim that needs a check**, see below. Other doc rows (imitate, clone, promotion codes, theme, parameter routes, es-search, settings) match the code [verified].
8. No dead code found in the nine files; `ConfigRules::__construct` default `[]` and the `?ConfigRules $config = null` fallback exist for tests (SystemConfigGuardSubscriber.php:34-39) [verified].

### Not guarded, suspected from memory of Shopware (all [inferred], none verified in code)
Checked against docs/architecture.md "Paths around the data layer" and "Limitations"; none of the following has a guard in src/Subscriber [verified by file list and grep of route names].
- **Store API proxy** `/api/_proxy/store-api/{salesChannelId}/...`: runs a storefront request in the named channel; no sales channel check by this plugin; docs claim it reads through repositories, which is doubtful because Store API routes read in a SalesChannelContext (`SalesChannelApiSource`, not an `AdminApiSource`, so `ChannelScope` returns `null`). Likely gap: any limited user with the proxy privilege could see products and categories of other channels. `api.proxy.switch-customer` and the imitate token are guarded, the rest are not.
- **Admin order creation** (`/api/_proxy-order/{salesChannelId}/...` or `order` insert via proxy): a foreign `salesChannelId` in the path; the write guard checks `order.sales_channel_id` only for the repository write [inferred].
- **Document generation, download, deep links** (`/api/_action/order/document/{type}/create`, `/api/_action/document/{id}/{deepLinkCode}`): loads orders with the user context in most versions but not necessarily with search events; the download deep-link route is public by design [inferred]. docs/presets.md lines 27-35 only discuss privileges.
- **Mail sending** (`/api/_action/mail-template/send`, `api_send_email`): takes `salesChannelId` in the body and sends through that channel's mail config [inferred].
- **Import/Export** (`/api/_action/import-export/*`): docs say presets do not grant it and filtering is "not tested end to end" (docs/architecture.md:161, 223) [verified for the docs]; imports write through the repository, so the write guard should apply [inferred].
- **Order state transitions** (`/api/_action/order/{id}/state/{transition}`): docs say none needed (:158), based on repository reads; state-machine writes may run in system scope [inferred].
- **Media download / upload** (`/api/_action/media/...`): media is not channel-scoped anywhere in the plugin (`media` is in the leak check's NOT_FOLLOWED, tests/acceptance/leak-check.php) [verified for NOT_FOLLOWED].
- **Sync API** (`/api/_action/sync`): goes through `PreWriteValidationEvent` guards (docs/architecture.md write guard section), not a separate bypass [verified for docs; inferred for completeness]. Deletes by `_action` ops with `entity` keys also pass the write guard [inferred].
- **System config schema and check routes** (`/api/_action/system-config/schema`, `/check`, `/batch` read variants): only value/save/batch are guarded; schema exposure is low risk [inferred].
- Other plain SQL endpoints (statistics, reports, `/api/_action/product-export/preview`, SEO URL template preview, sitemap): docs/architecture.md:233 says "may show figures of all channels" [verified for docs].

## Open questions
- Why `core` and `storefront` are listed as whole readable domains instead of an allow-list, and whether the route guard is meant to filter hidden sub-keys of a parent domain (SettingsDomains.php:45).
- Why clone has only product and cms_page logic; is the copy of other entity types intended to stay as is?
- Why `KERNEL_CONTROLLER_EVENT_SCOPE_VALIDATE_POST` was chosen, and whether the ACL check always happens earlier.
- Why integrations are never limited (docs/architecture.md "Limitations" names it as future work only).
- Is the "Store API proxy: none needed" statement (docs/architecture.md:158) based on a test? The leak check (tests/acceptance/leak-check.php) is the only candidate and was not read in full.
- Whether the Import/Export and document/mail routes were reviewed in the 0.12.0 security review beyond what the changelog lists.

## Verified by /onboard (2026-11-17)
S1 [verified]: as the CI user with the settings preset, `GET /api/_action/system-config?domain=core` returned 71 keys including `core.mailerSettings.password` and `.username` (HTTP 200), while `domain=core.mailerSettings` and `domain=core.store` were refused (403). Likely gap 1 above is confirmed.

0.12.2: S1 fixed. `SystemConfigGuardSubscriber::onResponse()` removes keys of hidden domains from allowed reads; verified in the test shop (`domain=core` 71 → 58 keys, no mailer keys) and by the leak check's new *Settings* section, which reports the old code as a leak [verified].
