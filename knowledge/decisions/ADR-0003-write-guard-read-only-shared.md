---
id: ADR-0003
status: proposed
scope: read-write-rules
created: 2026-11-17
source: /onboard
---
# Writes: shared records are read-only; hard-coded guard plus tagged WriteRules

## Context
A record used by several channels (product, category, layout, promotion) must not be changed by a user who holds only one of them [verified: README table].

## Decision
`WriteGuardSubscriber` (629 lines, hard-coded per entity) and `WriteRuleSubscriber` (tagged `WriteRule` services) look up each written record's channels via `ScopeLookup` and refuse writes outside the user's channels [verified].

## Alternatives considered
Not documented; one rule system for all entities (the tagged one) [inferred].

## Consequences
Read and write entity lists are kept in sync by hand; mapping tables without a rule are writable (verified S2: `order_tag`) [verified]. One uncached lookup per write command, slow for large Sync batches [verified: read-write-rules.md].
