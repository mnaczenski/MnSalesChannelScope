# Sales Channel Scope (Shopware 6.7 plugin, proof of concept)

Limits admin roles to sales channels: reads filtered in the data layer, writes and endpoints around it guarded, shared records read-only. Security-relevant code: every change needs a test, and gaps found must be recorded. Start with `docs/system/overview.md` (including the verified security findings), then `docs/system/modules/`, and the plugin's own `docs/architecture.md`. Decisions and learnings: `knowledge/INDEX.md`. Features: `specs/INDEX.md`.

## Commands
All verified on 2026-11-17 in a dockware Shopware 6.7.16 shop (PHP 8.2, Node 24). The PHP tests need Shopware's classes: `tests/bootstrap.php` uses `SHOPWARE_DIR`, the shop the plugin sits in, or the plugin's own `vendor/`.

| What | Command | Result on 2026-11-17 |
|---|---|---|
| Unit tests | `SHOPWARE_DIR=<shop root> <shop root>/vendor/bin/phpunit --testsuite unit` (or `composer test` after `composer install`, as CI does) | 128 tests, 1 skipped |
| PHPStan (level 8) | `SHOPWARE_DIR=<shop root> vendor/bin/phpstan analyse --memory-limit=2G` (or `composer phpstan`) | no errors |
| JS tests | `node --test tests/js/*.test.mjs` | 11 tests pass |
| Preset files | `for f in src/Resources/config/presets/*.json; do python3 -m json.tool "$f" >/dev/null; done` | valid |
| Admin override check | `php tests/ci/check-admin-overrides.php <shop>/vendor/shopware/administration/Resources/app/administration/src` | 14 components, 0 problems |
| Leak check | plugin active in a shop; `php tests/ci/seed.php <shop root> > env` (prints `SCOPE_USER`, `SCOPE_PASSWORD`, `SCOPE_FOREIGN_*`); `for p in orders products categories marketing cms settings; do bin/console mn:channel-scope:preset $p scope-ci; done`; export the env; `SCOPE_SHOP_URL=<shop url> php tests/acceptance/leak-check.php` | "No leaks found" (reads only; see the findings in the overview) |

**Known issue:** `composer test:js` and CI use `node --experimental-detect-module --test tests/js/`. A directory argument works on Node 20 (CI) but fails on Node 22 and newer ("Cannot find module"). Use the glob form above locally.

Never print `SCOPE_PASSWORD` or other credentials from the seed output.

## Conventions (observed in the code)
- `declare(strict_types=1);` in every PHP file; constructor injection with `private readonly`; services in `src/Resources/config/services.php` (PHP config); 9 classes `@internal`, public API listed in `docs/compatibility.md` and `docs/extending.md`.
- Scope rules: a new scoped entity needs a read rule (`ScopeRules` or a tagged `ReadRule`) **and** a write rule (`WriteGuardSubscriber` or a tagged `WriteRule`); the lists are kept in sync by hand, so add both, with tests.
- Logic classes depend on storage interfaces (`CategoryTreeSource`, `CmsUsageSource`, `PromotionSource`, `ScopeLookup`, `RoleChannelLoader`) with `Dbal*` implementations; unit tests use the static doubles in `tests/Static*.php` and ids from `tests/Ids.php`.
- Guards match core route names and events by string; when Shopware renames one, the guard silently stops working, so check them against new Shopware versions.
- Errors: `ChannelScopeException` with `MN_CHANNEL_SCOPE__WRITE_DENIED`, `__SETTINGS_DENIED`, `__ROUTE_DENIED`.
- Admin texts in English and German (`src/Resources/app/administration/src/snippet`); the admin build in `src/Resources/public` is committed (Shopware's `bin/build-administration.sh` in the shop root).
- Every version gets a `CHANGELOG.md` entry; keep `docs/architecture.md` in sync with the rules (the module docs list current mismatches).
