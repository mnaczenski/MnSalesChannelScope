---
id: ADR-0001
status: proposed
scope: global
created: 2026-11-17
source: /onboard
---
# Enforce channel scope in the data layer, like Shopware's media visibility

## Context
Shopware's ACL grants privileges per entity type, never per record [verified: README "Why"].

## Decision
A plugin (not an app) adds a filter to every search and aggregation of a scoped entity in a subscriber, the same mechanism as Shopware's `MediaVisibilityRestrictionSubscriber`, and guards writes and the endpoints that bypass the data layer [verified: src/Subscriber/ReadFilterSubscriber.php, docs/architecture.md].

## Alternatives considered
Core changes to the ACL (record-level permissions); an app (can't hook into the data layer) [inferred]. Reasoning not documented beyond the README.

## Consequences
Works without core changes. Anything that bypasses the data layer (raw SQL endpoints, OpenSearch, proxies) needs its own guard, and a missed one leaks (see overview, verified findings) [verified]. Every scoped search pays for extra joins [verified: read-write-rules.md].
