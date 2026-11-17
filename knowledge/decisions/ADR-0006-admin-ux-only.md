---
id: ADR-0006
status: proposed
scope: administration
created: 2026-11-17
source: /onboard
---
# The administration is UX only; the server enforces everything

## Context
Limited users need to see what they may change [verified: README].

## Decision
Admin overrides mark records read-only, hide menu entries and settings, pre-select channels; they enforce nothing, the server-side rules do [verified: administration.md].

## Alternatives considered
Not documented.

## Consequences
A broken override only degrades the UX, not security [inferred]. 14 core components are overridden; a CI check verifies their hooks still exist [verified: 14 checked, 0 problems on 6.7.16].
