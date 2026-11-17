---
id: ADR-0007
status: proposed
scope: global
created: 2026-11-17
source: /onboard
---
# Compatibility: semver from 1.0; 0.x may break; guards may become stricter any time

## Context
The plugin is a proof of concept (0.12.1) [verified].

## Decision
docs/compatibility.md: public API (tags, rule interfaces, presets format, settings domains, exceptions, admin service, API routes, role association) follows semver from 1.0; until then minor versions may break; built-in guards and rules may become stricter in any version ("a stricter rule is a fix, not a break") [verified].

## Alternatives considered
Not documented.

## Consequences
Closing the verified gaps (S1–S4) is allowed in any version under this policy [verified].
