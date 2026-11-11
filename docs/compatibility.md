# Compatibility policy

Sales Channel Scope follows [semantic versioning](https://semver.org) for its **public API** from 1.0 on. Until then (0.x), minor versions may change the public API; every change is in the changelog.

## What is public API

The extension points listed in [extending](extending.md#public-api):

- `Mn\SalesChannelScope\Tags` and the service tags it names
- `Rule\ReadRule`, `Rule\WriteRule`, `Rule\WriteScope`, `Rule\SalesChannelColumnRule`, `Rule\ChannelColumnLookup`
- `Preset\PresetDirectory` and the preset file format
- `Scope\SettingsDomains`, `Scope\ChannelScope::salesChannelIds()`, `Scope\RoleChannelLoader`
- `Exception\ChannelScopeException` and its error codes (`MN_CHANNEL_SCOPE__WRITE_DENIED`, `MN_CHANNEL_SCOPE__SETTINGS_DENIED`, `MN_CHANNEL_SCOPE__ROUTE_DENIED`)
- the administration service `Shopware.Service('mnChannelScope')`
- the API routes `GET /api/_action/mn-channel-scope/me`, `/cms-page/{id}`, `/presets` and their response fields
- the role association `acl_role.mnSalesChannels`

## What is not

- The built-in guards, lookups and `ScopeRules`: what they allow can become stricter in any version when a gap is found. A stricter rule is a fix, not a break.
- Administration component overrides and their markup.
- Database tables (`mn_acl_role_sales_channel`, `mn_cms_page_sales_channel`): use the role association and the API.

## Supported Shopware versions

`composer.json` names them (currently `~6.7.14`: Shopware 6.7.14 and newer 6.7 releases). Raising the minimum is announced in the changelog.

## How compatibility is checked

Every push and pull request runs `.github/workflows/tests.yml`:

- **PHPStan (level 8) and the unit tests** against the lowest and the highest installable Shopware 6.7, on PHP 8.2 to 8.4.
- **An installed shop** for Shopware 6.7.14.0 (the minimum), 6.7.16.0 (the latest release) and `trunk` (the next minor; allowed to fail, as an early warning).
- **The administration internals the plugin overrides** (`tests/ci/admin-overrides.json`: 14 components, their methods, computed properties and Twig blocks) are checked against each of these versions. A renamed method or block would make an override silently do nothing; the check fails instead. It found three gaps before 6.7.14, which is why that is the minimum.
- **The administration services** with node's test runner.

## Known risks

The plugin builds on Shopware internals where no extension point exists. These are the places to check after a Shopware update:

| Where | Depends on | If it changes |
|---|---|---|
| Read-only records in the administration | `acl.can()` of Shopware's `acl` service is wrapped while a shared record is open | fields stop disabling themselves; the server still refuses the change |
| Sales channel switch | the switch's select block is replaced (no *All Sales Channels* option for limited users) | the option reappears; the server still refuses settings for all channels |
| Settings pages, SEO templates | `sw-system-config.readAll`, `sw-seo-url-template-card.fetchSeoUrlTemplates` load inherited values first | fields can render empty for limited users; caught by the override check if renamed |
| Admin search | `sw-search-bar.adminEsEnable` | limited users would call the OpenSearch route and be refused |
| Settings, clone, theme, customer login, promotion codes | route names (`api.action.core.save.system-config.batch`, `api.clone`, `api.action.theme.assign`, `api.proxy.generate-imitate-customer-token`, …) and the validation event `framework.validation.impersonation.generate-token` | a renamed route is no longer guarded; check the route guards after every Shopware update |
| Dashboard | `OrderAmountService` is decorated | the turnover chart shows all channels |
| Presets | the administration's role definitions | a module needs a privilege the preset lacks; re-run `bin/expand-preset.js` |

Every new Shopware version should also be checked for new endpoints that read or write without the data layer; see [architecture](architecture.md#paths-around-the-data-layer).
