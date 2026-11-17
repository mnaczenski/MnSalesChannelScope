# System overview
_Last updated: 2026-11-17 by /onboard_

Legend: [verified] = seen in code, tests, git or by running it; [inferred] = interpretation.

## What the system is
Sales Channel Scope (`mn/sales-channel-scope`, version 0.12.1, status "proof of concept") is a Shopware 6.7 **plugin** that limits admin roles to sales channels [verified: composer.json, README.md]. A user whose roles all have sales channels sees and changes only what belongs to those channels: orders, customers, products, categories, Shopping Experiences, promotions, settings, dashboard. Reads are filtered in the data layer (the mechanism of Shopware's `MediaVisibilityRestrictionSubscriber`); writes and the endpoints that bypass the data layer are guarded; records shared with other channels are read-only [verified: docs/architecture.md, src/Subscriber]. The plugin has its own design docs (`docs/architecture.md`, `extending.md`, `presets.md`, `testing.md`, `compatibility.md`); the module docs below compare them with the code.

## Tech stack
- PHP ≥ 8.2, Shopware core and administration `~6.7.14`; services in `src/Resources/config/services.php`, routes in `routes.php` [verified]
- Two own tables via migrations: `mn_acl_role_sales_channel`, `mn_cms_page_sales_channel` [verified]
- Administration: 14 overrides of core admin components plus services (`src/Resources/app/administration/src`), committed Vite build in `src/Resources/public` [verified]
- Tests: PHPUnit 11 (128 unit tests), PHPStan level 8, Node test runner (11 JS tests), an acceptance leak check against a running shop, a check of overridden admin internals [verified: all run on 2026-11-17]
- CI: GitHub Actions; static job on PHP 8.2–8.4, integration job (override check, seed, presets, leak check) on Shopware 6.7.14, 6.7.16 and trunk (trunk allowed to fail), JS job on Node 20, preset JSON lint [verified: .github/workflows/tests.yml]

## Modules
| Module | Responsibility | Doc |
|---|---|---|
| Scope core | Who is limited and to which channels (`ChannelScope`), built-in read filters (`ScopeRules`), settings rules, role↔channel mapping, migrations | [scope-core.md](modules/scope-core.md) |
| Read & write rules | Read filter on every search incl. associations; write guard (`WriteGuardSubscriber`) and tagged `WriteRule`s; entity → rule table | [read-write-rules.md](modules/read-write-rules.md) |
| Guards around the data layer | Admin search (OpenSearch), routes with a channel parameter, clone, imitate customer, state history, system config, themes, Shopping Experiences, promotions | [guards.md](modules/guards.md) |
| Ownership sources | Derived ownership of categories (trees), Shopping Experiences (usage), promotions; per-channel totals and dashboard turnover | [ownership-sources.md](modules/ownership-sources.md) |
| Permission presets | JSON presets of ACL privileges for limited teams, discovery from plugins, admin card and CLI `mn:channel-scope:preset`, `bin/expand-preset.js` | [presets.md](modules/presets.md) |
| Administration | Role field and preset card, read-only marking, hidden menu/settings, pre-selected channels, admin controller | [administration.md](modules/administration.md) |
| Tests, CI & leak check | Unit, JS, PHPStan, leak check, override check, seed | [tests-ci.md](modules/tests-ci.md) |

## How modules interact
1. `ChannelScope::salesChannelIds()` resolves, per request and user, the union of the user's role channels, or `null` (unrestricted) for admins, integrations, system scope, users with a role without channels [verified: src/Scope/ChannelScope.php:29-80].
2. `ReadFilterSubscriber` asks `ScopeRules` and the tagged `ReadRule`s for a filter per entity and adds it to every search and aggregation; ownership of categories, layouts and promotions comes from the ownership sources [verified].
3. Writes pass `WriteGuardSubscriber` (hard-coded) and `WriteRuleSubscriber` (tagged `WriteRule`s), which look up each record's channels via `ScopeLookup` [verified].
4. Endpoints around the data layer are refused or checked by the guard subscribers [verified: guards.md].
5. Presets give roles the privileges a scoped team needs; the admin card and CLI apply them [verified].
6. The admin reads `/api/_action/mn-channel-scope/me` to mark records read-only and hide what the user can't use; it enforces nothing [verified].

## Cross-cutting concerns (auth, config, i18n, caching, events)
- **Default open:** unlimited unless every role of the user has channels; integrations (API keys) are never limited [verified]. New entities without a read rule are unfiltered; new `core.*` settings domains are readable unless hidden [verified: scope-core.md].
- **Caching:** only per request (`kernel.reset`), no cross-request cache [verified].
- **i18n:** English and German admin snippets [verified].
- **Extension:** `ReadRule`/`WriteRule` tags, `PresetDirectory`, `SettingsDomains`, the admin service `mnChannelScope`; policy in docs/compatibility.md (semver from 1.0; 0.x minors may break) [verified].

## Verified security findings (2026-11-17, test shop Shopware 6.7.16, CI test data and all six presets)
| # | Finding | How verified | Severity [inferred] |
|---|---|---|---|
| S1 | A limited user with the settings preset reads **all `core.*` settings, including `core.mailerSettings.password` and `.username`**, via `GET /api/_action/system-config?domain=core`; the direct `domain=core.mailerSettings` request is correctly refused (403). The guard checks the requested domain, not the returned keys. | ran it as the CI user with a marker password (key names only) | high (credentials). **Fixed in 0.12.2**: response filtered; leak check section *Settings* added and shown to catch the old code |
| S2 | A limited user can **tag another channel's order** (`order_tag` via Sync API and `POST /api/order-tag`); the orders preset grants `order_tag` writes and no rule checks them. | ran it; admin saw the tag on the foreign order | medium (integrity; flows may react to tags). **Fixed in 0.12.2**, together with `customer_tag` |
| S3 | A limited user who is given role-management privileges (`acl_role:create`, `acl_user_role:create`) can **create a role without channels, assign it to themselves and become unrestricted**. Not granted by any preset. | ran it; foreign order went from 404 to 200 | medium (needs a deliberate privilege grant). **Accepted as known limitation** (user, 2026-11-17; ADR-0008), documented in README and docs/architecture.md |
| S4 | Deleting a role's last sales channel deletes its mapping rows (`ON DELETE CASCADE`) and the role becomes **unrestricted** (`ChannelScope::resolve()` treats an empty list as unlimited). Not reproducible with a channel that has orders: Shopware refuses that delete (FK 1451). | code [verified]; reproduction blocked by FK | medium (only channels without orders, e.g. before go-live). **Accepted as known limitation** (user, 2026-11-17; ADR-0008), documented in README and docs/architecture.md |

The acceptance leak check passed ("No leaks found") because it only read entities and five endpoints; S1–S3 were outside what it covered [verified]. Since 0.12.2 it also checks settings reads (S1); writes (S2) are covered by unit tests [verified].

Further suspected gaps (not verified, see module docs): clone guard only handles `product` and `cms_page`; Store API proxy, mail send, document generation, import/export not guarded; `customer_tag` and `product_cross_selling_assigned_products` without rules; refund positions written through the refund.

## Global hotspots
From git history (23 commits, 2026-11-10 to 2026-11-13, one author) [verified: `git log --name-only`]:

| File | Commits |
|---|---|
| src/Resources/config/services.php | 11 |
| admin snippets en-GB / de-DE | 11 each |
| src/Subscriber/WriteGuardSubscriber.php (629 lines, hard-coded) | 10 |
| src/Scope/ScopeRules.php | 10 |
| src/Resources/app/administration/src/main.js | 10 |
| role view override, `src/Write/ScopeLookup.php` / `DbalScopeLookup.php` | 6–8 |

## Open questions
1. ~~S1–S4: fix?~~ Answered 2026-11-17: S1 and S2 fixed in 0.12.2, S3 and S4 accepted and documented (ADR-0008).
2. Default-open behaviour (integrations, roles without channels, deleted channels, unlisted entities, new `core.*` settings): intended beyond the proof of concept? (author / product owner)
3. Is a production rollout planned, and by when? The README says "not for production use yet". (product owner)
4. Should the leak check also cover writes? Settings are covered since 0.12.2. (author)
5. How is the committed admin build produced and kept in sync with the sources? (author)
