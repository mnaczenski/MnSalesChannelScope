# Capabilities (as built)
What the product does today, from a user's perspective. This is the baseline new PRDs build on.
_Written 2026-11-17 by /onboard. "Tested" = unit tests exist [verified]; "leak-checked" = covered by tests/acceptance/leak-check.php, which passed on 2026-11-17 [verified]. Why: only where README/docs state it._

| Capability | Users | Modules | Status | Why (if known) |
|---|---|---|---|---|
| Limit an admin role to sales channels (role field "Sales channels"); a user is limited when all roles have channels, to their union | Administrators | Scope core, Administration | built, tested | Shopware's ACL grants per entity type, never per record (README "Why") |
| Orders, customers, products, reviews of other channels invisible (lists, details, search, Admin API) | Limited users | Read & write rules, Guards | built, tested, leak-checked | Teams per brand/country/B2B should see only their channel (README) |
| Shared records (products, categories, layouts, promotions in several channels) read-only | Limited users | Read & write rules, Administration | built, tested; write paths not leak-checked (see S2) | |
| Categories by the channels' trees; Shopping Experiences and landing pages by usage; new layouts belong to the creator's channels | Limited users | Ownership sources | built, tested (logic), SQL untested | |
| Promotions of own channels only; promotion codes guarded | Limited users | Ownership sources, Guards | built, tested | |
| Per-channel settings editable; settings for all channels stay with administrators | Limited users | Guards (system config), Scope core | built, tested; **leaks `core.*` incl. mail credentials (S1)** | |
| Dashboard turnover and totals (order counts, ratings) for own channels | Limited users | Ownership sources | built, partly tested; filters/sorting still use global totals (documented) | |
| Admin search incl. OpenSearch scoped | Limited users | Guards | built, tested | |
| Permission presets (Order management, products, categories, Marketing, Shopping Experiences, settings) via admin card or CLI; plugins can add presets | Administrators, developers | Presets, Administration | built, tested (CLI and expand script untested) | Shopware's permission tree grants too much or too little for scoped users [inferred from docs/presets.md] |
| Menu entries and settings a limited user can't use are hidden; own channels pre-selected for new products and promotions | Limited users | Administration | built, JS services tested, components untested | |
| Extension API: ReadRule/WriteRule tags, PresetDirectory, SettingsDomains, admin service `mnChannelScope` | Developers | Read & write rules, Presets, Scope core | built, documented in docs/extending.md | |

## Open questions about intent
- Is "unrestricted by default" (integrations, roles without channels, unlisted entities, new settings domains) the intended model, or a proof-of-concept shortcut? (author / product owner)
- Should limited users ever manage roles or users (S3)? (author)
- Should categories shared by two channels be read-only even for a user holding both channels? (author)
- Are totals in filters and sorting (global values) acceptable for production? (product owner)
- Should the global default layout stay uneditable for every single-channel user? (author)
