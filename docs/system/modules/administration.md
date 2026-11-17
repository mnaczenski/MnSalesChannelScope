# Module: Administration
_Path: src/Resources/app/administration/src (plus src/Controller/ChannelScopeController.php, src/Resources/config/routes.php, src/Resources/public, tests/ci, tests/js)_

Plugin version 0.12.1 [verified: composer.json:5]. Paths below are relative to the plugin root; `ADM` = `src/Resources/app/administration/src`.

## Responsibility
The administration module is the UX layer of the plugin: it shows the role's "Sales channels" field and the "Permission presets" card, marks records shared with other channels as read-only for limited users, hides settings and menu entries they cannot use, and pre-selects their channels for new products and promotions [verified: ADM/extension/sw-users-permissions-role-view-general/*, sw-product-detail/index.js:16-69, sw-promotion-v2-detail/index.js:10-31, sw-admin-menu/index.js:11-49]. It does not enforce anything; the code says the server enforces the rules (write guard, read filter) [verified: ADM/service/channel-scope.js:3-7, ChannelScopeController.php:16-20].

## Entry points and interfaces
- Entry: `ADM/main.js:1-19` imports the scss, 14 extension folders and registers the service `mnChannelScope` [verified].
- Public JS API for other plugins: `Shopware.Service('mnChannelScope')` from `ADM/service/public-api.js:13-31` exposing `load`, `loadCmsPage`, `loadPresets`, `registerReadOnlyGuard`, `canEditCategory`, `foreignChannelIds`, `addSettingsRoutePrefix`, `settingsRoutePrefixes`, `isSettingsRoute`, `isAllowedSettingsRoute`. Documented in docs/extending.md (not read in detail) [verified for code, inferred for docs content].
- Services: `ADM/service/api.js:4-16` (GET with bearer token via loginService), `channel-scope.js` (cached `me` call, read-only acl guard, CMS scope), `settings-scope.js` (allowed settings route prefixes: index, basic.information, cart, login.registration, newsletter, sitemap, seo, lines 9-17), `presets.js` (load, locale text fallback, `isPresetApplied`) [verified].
- Controller `src/Controller/ChannelScopeController.php`, route scope `api` (line 21), routes imported by attribute via `src/Resources/config/routes.php:6` [verified]:
  - `GET /api/_action/mn-channel-scope/presets` (l.36-51): returns name, label, description, roles, additionalPrivileges for each preset. ACL attribute `acl_role:read` (l.39).
  - `GET /api/_action/mn-channel-scope/cms-page/{pageId}` (l.57-70): restricted/editable/salesChannelIds of a layout. No ACL attribute declared.
  - `GET /api/_action/mn-channel-scope/me` (l.72-84): restricted, salesChannelIds, categoryEntryPoints own/foreign. No ACL attribute declared.
  - Protection: all are under the Admin API route scope, so they need a valid admin token [inferred from Shopware's ApiRouteScope]; only `presets` has a privilege check. `me` and `cms-page` return data about the caller's own scope; `cms-page` returns the layout's channel ids only when the layout is visible to the caller (l.68) [verified]. For unrestricted users `cms-page` answers editable=true without checking that the page exists (l.61-63) [verified]. Unit test: tests/Unit/Controller/ChannelScopeControllerTest.php [verified exists].
- Extension points (Twig blocks added by this plugin): `mn_sales_channel_scope_role_sales_channels`, `mn_sales_channel_scope_role_presets`, `mn_sales_channel_scope_product_read_only`, `mn_sales_channel_scope_category_read_only` [verified].
- Prebuilt assets in `src/Resources/public/administration/`: `assets/mn-sales-channel-scope-D2izPqYi.js` (15.7 KB), `assets/mn-sales-channel-scope-CDf0hy76.css` (1.1 KB), `.vite/entrypoints.json`, `.vite/manifest.json`; all tracked in git [verified: git ls-files]. Entrypoint base `/bundles/mnsaleschannelscope/administration/` [verified]. No package.json or build config exists in the plugin, so how it is built is not visible here [verified absent; inferred: built by Shopware's admin build].

## Data model
No tables or migrations in this module [verified: none under ADM]. It reads/writes through Shopware repositories: `acl_role` with the association `mnSalesChannels` (an extension defined elsewhere; loaded by wrapping `roleRepository.get` in a Proxy, `ADM/extension/sw-users-permissions-role-detail/index.js:9-31`), `promotion_sales_channel` (created in `sw-promotion-v2-detail/index.js:19-26`), `seo_url_template` (`sw-seo-url-template-card/index.js:20`), product `visibilities`. Snippets: `ADM/snippet/en-GB.json` and `de-DE.json`, 30 lines each, namespace `mn-sales-channel-scope.*` [verified].

## Dependencies
- Uses: server routes above and Shopware admin services (`acl`, `loginService`, `repositoryFactory`, `systemConfigApiService`), stores `swProductDetail`, `swCategoryDetail`, `session`, global `Shopware.Context.app` [verified]. Server side: `ChannelScope`, `CategoryTrees`, `CmsPages`, `PermissionPresets` injected into the controller (ChannelScopeController.php:24-29); `AdminSearchGuardSubscriber` is the server counterpart of the search-bar override [verified]. The acl_role extension `mnSalesChannels` must exist for the role field (template has `v-if="mnSalesChannels"`) [verified/inferred].
- Used by: other plugins through `mnChannelScope` (none in this repo) [inferred]; the plugin's server modules do not import admin code [inferred].

## Patterns and conventions
- Override pattern: `Shopware.Component.override('<name>', {...})`, state named `mn*`, load scope once with `loadChannelScope()` in `created()` [verified in all extensions]. Twig templates only through blocks with `{% parent %}`.
- Read-only: computed `mnReadOnly`, a disabled replica Save button, `onSave` guard that also covers the shortcut, and `registerReadOnlyGuard` which monkey-patches `acl.can` once to answer false for `<entity>.editor/creator/deleter` while the record is open (`ADM/service/channel-scope.js:75-99`) [verified]. Used by product, category, CMS.
- Failure mode: API errors fall back to "unrestricted" (`channel-scope.js:15-19`, `loadCmsPageScope` l.107, presets -> []) [verified]. Acceptable only because the UI is not the enforcement.
- A typical change: edit an extension folder, add component, members and blocks to `tests/ci/admin-overrides.json`, add snippets in both languages, rebuild the public assets [verified from docs/testing.md "After adding an administration override..."].

### Overridden core components and CI coverage
`tests/ci/check-admin-overrides.php` checks, per component, that the directory exists, the listed methods/computed props exist (regex on `name(` or `name:`, l.51) and the listed Twig blocks exist (l.56) against `tests/ci/admin-overrides.json` [verified]. It runs in CI against 6.7.14.0, 6.7.16.0 and trunk (allowed to fail) [verified: docs/testing.md; .github/workflows/tests.yml:60 only partly read].

| Component | Override file | What is overridden | In admin-overrides.json |
|---|---|---|---|
| sw-admin-menu | extension/sw-admin-menu/index.js:11 | computed `navigationEntries`, router `beforeEach` | members: navigationEntries (json l.2) |
| sw-settings-index | extension/sw-settings-index/index.js:8 | computed `settingsGroups` | members (l.3) |
| sw-search-bar | extension/sw-search-bar/index.js:11 | computed `adminEsEnable`, also writes `Shopware.Context.app.adminEsEnable` | members (l.4) |
| sw-sales-channel-switch | extension/sw-sales-channel-switch/index.js:9 + twig (replaces block, copies the whole select) | `onChange`, `salesChannelId`, `salesChannelCriteria`, `disabled`, `label`, block `sw_sales_channel_switch_select` | members onChange, salesChannelId; block (l.5). `salesChannelCriteria`, `disabled`, `label` used in twig but not checked |
| sw-system-config | extension/sw-system-config/index.js:8 | `readAll`; touches `actualConfigData`, `initialConfigData`, `currentSalesChannelId`, `systemConfigApiService`, `domain` | members first four (l.6); `systemConfigApiService`, `domain` not checked |
| sw-seo-url-template-card | extension/sw-seo-url-template-card/index.js:10 | `fetchSeoUrlTemplates`; `defaultSeoUrlTemplates`, `seoUrlTemplateRepository` | members (l.7) |
| sw-settings-seo | extension/sw-settings-seo/index.js:11 + twig | `isLoading`, block `sw_settings_seo_content_inner_redirect` | covered (l.8) |
| sw-users-permissions-role-detail | extension/sw-users-permissions-role-detail/index.js:9 | computed `roleRepository` (Proxy) | covered (l.9). The parent passing `detailedPrivileges` to child views is relied on (view-general index.js:13-20) and not checked |
| sw-users-permissions-role-view-general | extension/.../index.js:8 + twig | two blocks; uses `role`, `isReadOnly`, `isLoading`, prop `detailedPrivileges`; components `mt-card`, `mt-button`, `mt-icon`, `sw-entity-multi-select` | blocks covered (json l.10); `role`, `isReadOnly`, `isLoading` and the child components not checked |
| sw-product-detail | extension/sw-product-detail/index.js:16 + twig | `onSave`, `getDefaultSalesChannels`, `fetchSalesChannelByIds`; blocks `..._actions_save`, `..._save_context_menu`, `..._content_language_info`; uses `product`, `parentProduct`, `productId`, store `swProductDetail` | members and blocks covered (l.11); `product`, `parentProduct`, `productId`, the store not checked |
| sw-category-detail | extension/sw-category-detail/index.js:11 + twig | `onSave`, block `sw_category_smart_bar_save_category`; `category` | covered (l.12); `category` not checked |
| sw-category-view | extension/sw-category-view/index.js:8 + twig | block `sw_category_view_column_info`; store `swCategoryDetail` | block covered (l.13) |
| sw-cms-detail | extension/sw-cms-detail/index.js:11 + twig | `canSave`, `onSave`, watch `pageId`, three blocks; `page.locked`; `sw-overlay` | members and blocks covered (l.14); `page`, watch `pageId` semantic not checked |
| sw-promotion-v2-detail | extension/sw-promotion-v2-detail/index.js:10 | `savePromotion`; `promotion.salesChannels` | member covered (l.15); `promotion` not checked |

All 14 overridden components are listed in the JSON [verified]. Gaps are outside what the check can see: the check is a name regex, it cannot detect a changed signature, changed return shape (e.g. `getDefaultSalesChannels` result keys, `navigationEntries` entry fields `id/parent/path`, `settingsGroups` shape with `setting.to`), or removed data/props [inferred]. Components from other places (`$router`, acl service replaced with a patched `can`) are also unchecked [verified].

## Tests
- JS: `tests/js/channel-scope.test.mjs` (64 lines), `settings-scope.test.mjs` (37), `presets.test.mjs` (33), stub `shopware-stub.mjs` (fake httpClient/acl/loginService). Run with `composer test:js` = `node --experimental-detect-module --test tests/js/`, node 20 in CI [verified: docs/testing.md]. I could not run it: `node` is not installed in this environment [verified].
- Coverage impression: only the services are tested. The 14 extension components, templates and the built bundle have no tests [verified: no other JS tests exist; inferred coverage]. The CI override check and the leak check (tests/acceptance/leak-check.php, tests/ci/seed.php) cover Shopware compatibility and server scope, not admin behavior [verified].
- Controller: tests/Unit/Controller/ChannelScopeControllerTest.php (PHPUnit) [verified exists; content not read].

## Hotspots
Git history has 23 commits, 2026-11-10 to 2026-11-13 [verified: git log]. Files touched in admin paths: snippets en-GB/de-DE (11 commits each), `ADM/main.js` (10), `sw-users-permissions-role-view-general` index.js (8) and twig (4), `service/channel-scope.js` (5), `sw-product-detail/index.js` (4), `ChannelScopeController.php` (4), `.vite` manifest/entrypoints and built assets (15 each, rebuilt for almost every release) [verified]. Most recent activity: 0.12.0 and 0.12.1 (product pages for limited users) [verified].

## Risks
- Fragility against Shopware updates: 14 core components overridden, one (`sw-sales-channel-switch`) by copying a whole block body, which silently diverges if Shopware changes that block [verified twig l.1-20; inferred consequence]. `sw-cms-detail` adds banners by copying core class names. The mt-* component use (`mt-card`, `mt-banner`, `mt-button`, `mt-icon`) ties to Shopware 6.7 [verified].
- `registerReadOnlyGuard` monkey-patches the shared `acl.can` globally (channel-scope.js:83) and the patch is never undone, only guards are removed; a guard with an `isReadOnly` that throws would affect every acl check [verified/inferred]. It also depends on components using `acl.can` for disabling [verified comment l.63-67].
- `sw-admin-menu` registers `$router.beforeEach` every time the component is created and never removes it, so guards can pile up on remount [inferred from sw-admin-menu/index.js:43].
- Fail-open: any error from `me` makes the UI behave as unrestricted [verified]. Server still enforces, per the code comments.
- `me` and `cms-page` have no explicit ACL attribute (ChannelScopeController.php:57, 72) while `presets` has one; likely fine, but not verified against Shopware's default behavior [verified absence; inferred].
- Product read-only logic is replicated client-side (`foreignChannelIds`, `canEditCategory`) and must match server rules ("Same rule as the server's CategoryTrees", channel-scope.js:50) [verified]; drift is untested [inferred].
- Prebuilt bundle is committed and there is no build config in the repo; the hashed file must be rebuilt after each source change or the shop runs stale code [verified assets tracked; inferred risk]. I did not compare the bundle against source, only grepped that component names appear in it.
- `sw-settings-seo` sets `isLoading = false` as a workaround for a hidden card (index.js:22-24) and `sw-product-detail` uses `setTimeout(..., 0)` for ordering (l.82-87): timing-sensitive [verified].
- Hidden settings are limited to a hard-coded prefix list, but the settings route check `isAllowedSettingsRoute` is purely client side; direct API calls are the server's job [verified].
- Dead code: none found; `settingsRoutePrefixes`/`isSettingsRoute` are used within the module and in the public API [verified].
- The version in the task says POC; no README claim checked.

## Open questions
- Why `me` and `cms-page` have no ACL privilege while `presets` requires `acl_role:read`: intent not documented in the files read.
- Why `sw-sales-channel-switch` replaces the whole select block instead of binding props, and whether it was tested on trunk.
- How the public bundle is built (command, vite version 7.1.0 from entrypoints.json) and whether CI verifies that the committed bundle matches the source.
- Whether other plugins already use `mnChannelScope` (none found in this repo).
- Where the `acl_role.mnSalesChannels` extension is defined (another module; not examined).
- Whether the CI trunk job being "allowed to fail" means admin override breaks on trunk go unnoticed in practice.
