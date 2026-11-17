# Module: presets (Permission presets)
_Path: src/Preset, src/Command/ApplyPresetCommand.php, src/Resources/config/presets/*.json, bin/expand-preset.js, docs/presets.md, tests/fixtures/presets-*, tests/Unit/Preset, tests/js/presets.test.mjs_

Paths are relative to the plugin root. Marks: [verified] = read in the file cited; [inferred] = concluded from reading, not confirmed. Git: `git log --oneline` ran and showed recent commits (tip `5e38952` "CI: seed without .env...", then 0.12.1, 0.12.0), but no per-file hotspot analysis was done; hotspots below come from CHANGELOG.md. [verified for the log output, rest inferred]

## Responsibility
The module bundles the Shopware ACL privileges a limited team needs into named JSON "presets" and adds them to an admin role, either through a card on the role page or the CLI. It exists because Shopware's own role tree does not cover what e.g. the order module really calls (documents, mail, payment states) [verified: docs/presets.md:3, CHANGELOG.md:28]. It only adds privileges, never removes any [verified: src/Command/ApplyPresetCommand.php:49, admin `onMnApplyPreset` index.js:66-79]. It does not itself limit anything to sales channels; that is done by the other modules (write/read rules) [inferred from docs/presets.md:15].

## Entry points and interfaces
- `src/Preset/PermissionPresets.php`: `names()` :42, `all()` :52 (sorted by position then name :55), `privileges($name)` :66 (union of `roles` + `expandedPrivileges` + keys of `additionalPrivileges`, deduped, sorted), `load($name)` :83, private `files()` :98, `validate()` :122. [verified]
- `src/Preset/PresetDirectory.php:16`: value object holding a directory path. Extension point: any plugin registers a `PresetDirectory` service tagged `Tags::PRESET_DIRECTORY` (`mn_channel_scope.preset_directory`, src/Tags.php:17); docs/extending.md:158-171. [verified]
- Core directory service `mn_channel_scope.presets.core`, tag priority 100; `PermissionPresets` gets `tagged_iterator(...)`: src/Resources/config/services.php:179-182. [verified]
- CLI: `bin/console mn:channel-scope:preset <preset> <role name|id>`, src/Command/ApplyPresetCommand.php:17,29-30; registered services.php:183-185. [verified]
- HTTP: `GET /api/_action/mn-channel-scope/presets`, src/Controller/ChannelScopeController.php:36-52, ACL `acl_role:read`; returns name, label, description, roles, additionalPrivileges (NOT expandedPrivileges). [verified]
- Admin: `src/Resources/app/administration/src/service/presets.js` (`loadPresets` :9 cached once, `translated` :23, `isPresetApplied` :41); card in `src/Resources/app/administration/src/extension/sw-users-permissions-role-view-general/` (index.js:8 override, apply handler :66, twig card). The administration agent maps the UI; here only the preset interplay. [verified]
- Maintenance tool: `bin/expand-preset.js <preset.json> <shop root>` prints `{roles, privileges}`; reads admin `acl/index.(js|ts)` files from core administration, commercial, custom/plugins (lines 27-31), evaluates them in a `vm` sandbox (:62), merges same-key entries (:71-79), resolves `getPrivileges()` refs (:44-51, :86-98). [verified]

### Preset file format (src/Preset/PermissionPresets.php:11-23, docs/presets.md:62-86)
`name` (must equal file name, `^[a-z0-9_-]+$` :27), `position` (default 1000 :143), `label` (needs `en-GB` :139), `description`, `roles` (admin role keys, ticked in the grid), `additionalPrivileges` (privilege => reason), `expandedPrivileges` (entity privileges the roles expand to, hand-copied from the script output). [verified]

### Lifecycle
1. Discovery: for each tagged directory in iteration order, `glob(*.json)`; first directory wins per name (:105-111). The `files` cache is per service instance. [verified] Doc comments say "higher priority wins"; this relies on Symfony's tagged_iterator ordering by priority (highest first) [inferred - Symfony behaviour, not tested against a container; the unit test only passes directories in array order, PermissionPresetsTest.php:83-91].
2. Validation: lazy, on `load()`, throws `UnexpectedValueException` naming the file (:122-147). `all()` loads every preset, so ONE broken file from any plugin makes `all()` throw, i.e. the presets endpoint returns HTTP 500 and the card disappears (the admin `.catch` returns `[]` :10-14). [verified for the code path; the 500 is inferred]. Validation does not check privilege string format, role-key existence, or element types of `label`/`description`/`additionalPrivileges` values.
3. Admin apply: pushes `roles` into `role.privileges`, and `additionalPrivileges` keys into the `detailedPrivileges` prop when it is an array; nothing is stored until the user saves, and Shopware then expands role keys itself (docs/presets.md:9, index.js:61-79). So in the UI `expandedPrivileges` is unused. [verified]
4. CLI apply: reads `privileges()` (including `expandedPrivileges`), fetches `acl_role` by name OR id, merges with existing JSON, writes with raw SQL `UPDATE acl_role` (ApplyPresetCommand.php:38-55). [verified]

## Data model
- No own tables or migrations for this module [inferred: no preset references found outside the files above; a full migration listing was not done].
- Reads/writes the core table `acl_role.privileges` (JSON list) via DBAL, ApplyPresetCommand.php:38,52. [verified]
- Data is the six JSON files in `src/Resources/config/presets/` (sizes: roles / additional / expanded counts below). [verified]

### Built-in presets (position; roles; additional; expanded entries)
| name (pos) | Admin roles | additionalPrivileges | expanded |
|---|---|---|---|
| orders (10) | order.viewer/editor/creator/deleter, order_refund.* (4), orders.create_discounts, document.viewer, document.deleter | api_send_email, mail_header_footer:read, mail_template_media:read, document:delete, document_file:read/create/update/delete, order_transaction:update, order_transaction_capture:read, order_transaction_capture_refund_position:read/create/update/delete, order_line_item_download:read, version_commit:delete | 81 |
| products (20) | product.viewer/editor/creator/deleter, review.viewer/editor/deleter | none | 166 |
| categories (30) | category.viewer/editor/creator/deleter | none | 93 |
| marketing (40) | promotion.viewer/editor/creator/deleter + cms.viewer/editor/creator/deleter | none | 149 |
| cms (50) | cms.viewer/editor/creator/deleter | none | 90 |
| settings (60) | system.system_config, sales_channel.viewer, sales_channel.editor | none | 95 |
All verified from src/Resources/config/presets/{orders,products,categories,marketing,cms,settings}.json. Note docs/presets.md:15-23 table agrees. Marketing is a superset of cms (it lists the cms roles).

Notable grants in `expandedPrivileges` (verified from the JSON):
- orders: `order:create/update/delete`, `customer:update`, `api_proxy_switch-customer` (impersonate customers), `order_customer:*`, `state_machine_history:create`, `mail_template_type:update`, `document_base_config:delete`, `b2b_order_employee:*`, `version:delete`, `tag:create`, `user_config:create/update`.
- products: `product:create/update/delete`, `product_review:update/delete`, `bundle*:*` (Commercial), `product_stream:create/delete`, `product_manufacturer:create/delete`, `media:create/update`, `media_folder*:*`, `seo_url:update`, `tag:create`.
- categories: `category:*`, `cms_page:update`, `cms_block/section/slot:*`, `landing_page:update`, `media:create/update/delete`, `media_folder*:*`, `seo_url:update`, `swag_cms_extensions_*:update`.
- cms and marketing: `cms_page:create/update/delete`, `category:update`, `landing_page:update`, media and media-folder writes; marketing adds `rule:create/update`, `rule_condition:*`, `payment_method:update`, `shipping_method:update`, `promotion*:*`, `swag_dynamic_access_*_rule:create/delete`.
- settings: `system_config:create/update/delete`, `sales_channel:update`, `sales_channel_domain/country/currency/language/payment_method/shipping_method:*`, `product_export:create/update`, `product_sorting:*`, `seo_url_template:create/update`, `theme_sales_channel:*`, `product_visibility:create/delete`.

## Dependencies
- Uses: Doctrine DBAL `Connection` and `Uuid` (ApplyPresetCommand.php:5,7); Symfony Console; `Tags` (src/Tags.php); Shopware ACL role keys defined by core administration/Commercial (resolved only at maintenance time by bin/expand-preset.js); `acl_role` table. [verified]
- Used by: `ChannelScopeController` (src/Controller/ChannelScopeController.php:28,36-52), `ApplyPresetCommand`, admin role page override and `service/presets.js`. Other plugins use it through `PresetDirectory` + tag (docs/extending.md:158). [verified]
- Runtime coupling to the scope modules: none in code; the scope is enforced by WriteGuard/ReadFilter subscribers etc. (src/Resources/config/services.php:84-170). [inferred]

## Patterns and conventions
- A typical change: edit or add `src/Resources/config/presets/<name>.json`, re-run `node bin/expand-preset.js <file> <shop>` against a shop with matching core/Commercial versions, paste `privileges` into `expandedPrivileges`, adjust `docs/presets.md` and the unit-test expectations, add CHANGELOG entry (e.g. 0.11.2 added `version_commit:delete`, CHANGELOG.md:28; 0.10.x refreshed expansions, CHANGELOG.md:117). [verified]
- Every `additionalPrivileges` entry carries a reason string; convention only tested for `orders` (PermissionPresetsTest.php:50-56). [verified]
- Texts are per-locale maps with a fallback chain locale -> language -> en-GB -> first (presets.js:23-35). [verified]
- Expansion is deliberately duplicated: admin expands when saving, JSON copy serves the CLI only. [verified: docs/presets.md:84]

## Tests
- PHPUnit: `tests/Unit/Preset/PermissionPresetsTest.php` (7 tests: orders coverage, products coverage incl. Commercial entries, reason strings for orders, path-traversal name refused `../secrets`, shipped presets valid/ordered/de-DE present, extra directory + shadowing, invalid file). Fixtures `tests/fixtures/presets-extra/{orders,acme-newsletter}.json`, `presets-invalid/broken.json`. [verified]
- Node: `tests/js/presets.test.mjs` (translated, isPresetApplied, loadPresets caching), uses `tests/js/shopware-stub.mjs`. Run `node --experimental-detect-module --test tests/js/` (.github/workflows/tests.yml:80-84). PHPUnit config: phpunit.xml.dist. [verified; not run here]
- CI also validates JSON syntax of every preset (.github/workflows/tests.yml:86-93). [verified]
- Not covered: `ApplyPresetCommand` (no test file in tests/Unit/Preset; grep not done for Command tests in other dirs - tests/Unit has Category, Cms, Controller, Preset, Rule, Scope, Subscriber, no Command dir), `bin/expand-preset.js`, the presets controller action (not checked whether tests/Unit/Controller touches it), the Vue card, priority ordering via real container, and drift between `roles` and `expandedPrivileges`. [verified absence of a Command test dir; rest inferred]
- Coverage impression: good for loader logic, thin for the actual privilege content (only a few spot assertions for orders/products). [inferred]

## Hotspots
Git (verified by /onboard): `src/Resources/config/presets/orders.json` 4 commits, the most-changed preset. From CHANGELOG.md: `orders.json` (0.1.0, 0.10.x, 0.11.2), plus the refresh of all expansions after `expand-preset.js` was fixed (CHANGELOG.md:117), and the move to file-based presets in 0.11.0 (CHANGELOG.md:40). `orders.json` is the most-touched preset. [inferred from changelog]

## Risks
Privilege escalation / over-granting (all from preset JSON, verified unless marked):
1. **Unscoped entity writes.** The presets grant raw entity privileges with no channel concept: `media:*`, `media_folder*:*`, `media_thumbnail_size:*`, `media_default_folder:update` (categories/cms/marketing/products), `tag:create`, `rule:create/update` + `rule_condition:*`, `payment_method:update`, `shipping_method:update` (marketing), `customer:update` and `api_proxy_switch-customer` (orders), `product_manufacturer:create/delete`, `product_stream:*`, `state_machine_history:create`, `mail_template_type:update`, `document_base_config:delete`, `user_config:*`. docs/presets.md:56 says limited users may create rules but not change existing ones, and architecture.md:222-ish lists "Rules created by limited users can't be changed afterwards", so some are guarded by the plugin, but whether `payment_method:update`, `shipping_method:update`, media-folder/thumbnail-size config, `product_manufacturer`, `mail_template_type:update` and `document_base_config:delete` are guarded by a write rule was NOT verified. `media_default_folder` and `media_folder_configuration_media_thumbnail_size` changes affect all channels. [inferred - needs the write-rules agent]
2. **Doc vs data mismatch on delete.** docs/presets.md:50 notes `document.deleter` also allows deleting document settings (`document_base_config:delete`); this is global config. [verified]
3. **`settings` preset** grants `system_config:create/update/delete`, `sales_channel:update`, `sales_channel_domain:*`, `theme_sales_channel:*`, `product_export:*`. Safety relies entirely on `SystemConfigGuardSubscriber` (services.php:168), ThemeGuardSubscriber (:164) and WriteGuard; a role without sales channels (docs/presets.md:13 "works with and without sales channels") gets these privileges unscoped. [verified grant; guard behaviour not read]
4. **No-channel role.** Applying any preset to a role with no sales channels yields a full-reach role for these entities; the card/CLI do not warn. Whether an empty channel list means "unrestricted" is [inferred] from `ChannelScope::restricted` naming and docs/presets.md:13; not read.
5. **CLI writes raw SQL** into `acl_role` bypassing the DAL/ACL and the admin's expansion; any CLI user with DB access can inject any privilege, but it only takes preset-defined strings. Name resolution `name = :role OR id = :id` can match several rows (first returned is updated) if role names are duplicated. No `--dry-run`. [verified]
6. **Plugin-supplied presets are trusted.** Any plugin directory can ship a preset with arbitrary privileges (incl. `*`-like or admin keys) and it will show up for role admins; no allowlist, no validation of privilege syntax (only the shipped ones are regex-checked in the test, PermissionPresetsTest.php:96-99). The role-page card requires only `acl_role:read` to list (controller :39) and the user must already be able to edit roles to apply. [verified]
7. **Drift risk:** `expandedPrivileges` is a manual copy; after Shopware/Commercial updates the CLI path and the admin path produce different roles until someone re-runs the script (docs/presets.md:79-95). No test compares them. `additionalPrivileges` for orders lists `order_transaction_capture_refund_position:*` but `order_transaction_capture_refund:create/delete/update` come from roles, so refund-related coverage depends on expansions. [verified]
8. **Fragile script:** `bin/expand-preset.js` strips TS/ES syntax with regexes and evaluates third-party code in a `vm` sandbox, skipping files on error with only a stderr line (:59-67); a skipped file silently yields incomplete privileges. `vm.runInNewContext` is not a security boundary for untrusted plugin code [inferred]. Unknown role key aborts with an exception (:88).
9. **`isPresetApplied`** only checks role keys and additional privileges, so a role with the key ticked via CLI (which also writes keys) matches, but a role where Shopware removed a dependency role does not; if `detailedPrivileges` is null (permission grid not loaded) additional privileges are ignored (presets.js:41-47). The card can show "Added" while additional privileges are missing. [verified code, impact inferred]
10. **Stale docs line:** the architecture doc says the `settings` preset opens only the listed settings pages, but the preset also stores `system_config:*` for all keys; the page restriction is UI-side only (docs/architecture.md:~207: "every rule is enforced on the server" vs. server enforcement of system_config by SystemConfigGuardSubscriber, not read). [inferred]
11. Dead code: none found in this module. `PermissionPresets::names()` is used only by tests in the files read (grep not exhaustive). [inferred]

## Open questions
- Why `marketing` includes the full `cms.*` roles instead of referring to the `cms` preset (duplication of 90 privileges)? Not documented.
- Why `product_review:update` is in the `review.editor` expansion but docs/presets.md:48 says the product roles don't grant it and the review roles add it. Is the `products` preset intentionally bundling reviews?
- Why does the plugin grant raw `payment_method:update`/`shipping_method:update` in marketing (docs say needed for the promotion rule builder, but the stated reason is in docs, not intended scope)? Is it enforced by a write rule?
- Which entities in the grants above (media, tag, manufacturer, rule, document_base_config, mail_template_type) are covered by a ReadRule/WriteRule? Needs the scope/rules agent.
- What happens for a role with preset applied and zero sales channels - unrestricted by design?
- Intent of `position` gaps (10..60) and whether third-party presets should avoid collisions (fixture uses 45). Not documented.
- Is the tag priority actually honoured (core 100 wins over plugin default 0)? Not verified in a container.
- Is there a reason the CLI does not expand roles by itself (needs the shop's admin JS), and is a preset-vs-expanded consistency check planned?
