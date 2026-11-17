---
id: ADR-0005
status: proposed
scope: presets
created: 2026-11-17
source: /onboard
---
# Permission presets as JSON files with expanded privilege snapshots

## Context
Shopware's admin permission tree grants too much or too little for a scoped team [inferred from docs/presets.md].

## Decision
Presets are JSON files (`src/Resources/config/presets`), discoverable from other plugins via tagged `PresetDirectory` services, applied from the role page card or the CLI `mn:channel-scope:preset`; `expandedPrivileges` is a snapshot regenerated with `bin/expand-preset.js` [verified].

## Alternatives considered
Not documented.

## Consequences
Snapshots can drift from Shopware's ACL after updates; presets grant writes on unscoped entities (media, rules, tags, payment and shipping methods, `system_config`), so their safety depends on the guards [verified: presets.md].
