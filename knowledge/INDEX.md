# Knowledge index
One line per entry. Agents read this file first, then open only the entries relevant to their module or task.
Maintained by /learn, /retro and /tidy. Keep it short: retired entries are removed from this index.

## Learnings
| ID | Type | Scope | Summary | Status |
|---|---|---|---|---|

## Decisions
| ID | Title | Scope | Status |
|---|---|---|---|
| [ADR-0001](decisions/ADR-0001-data-layer-filter.md) | Enforce channel scope in the data layer, like Shopware's media visibility | global | proposed |
| [ADR-0002](decisions/ADR-0002-scope-model-default-open.md) | Scope model: limited only if every role has channels; everything else unrestricted | scope-core | proposed |
| [ADR-0003](decisions/ADR-0003-write-guard-read-only-shared.md) | Writes: shared records are read-only; hard-coded guard plus tagged WriteRules | read-write-rules | proposed |
| [ADR-0004](decisions/ADR-0004-derived-ownership.md) | Derive ownership of categories, layouts and promotions instead of storing it | ownership-sources | proposed |
| [ADR-0005](decisions/ADR-0005-permission-presets.md) | Permission presets as JSON files with expanded privilege snapshots | presets | proposed |
| [ADR-0006](decisions/ADR-0006-admin-ux-only.md) | The administration is UX only; the server enforces everything | administration | proposed |
| [ADR-0007](decisions/ADR-0007-compat-policy-0x.md) | Compatibility: semver from 1.0; 0.x may break; guards may become stricter any time | global | proposed |
| [ADR-0008](decisions/ADR-0008-known-limitations-s3-s4.md) | Role self-escalation (S3) and roles left without channels (S4) stay documented limitations | scope-core | accepted |
