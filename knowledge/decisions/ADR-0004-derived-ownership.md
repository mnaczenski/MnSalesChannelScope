---
id: ADR-0004
status: proposed
scope: ownership-sources
created: 2026-11-17
source: /onboard
---
# Derive ownership of categories, layouts and promotions instead of storing it

## Context
Categories, Shopping Experiences and promotions have no sales channel column [verified].

## Decision
Categories belong to the channels whose navigation/footer/service trees contain them; layouts to where they are used, plus an owner table `mn_cms_page_sales_channel` for new layouts; promotions via `promotion_sales_channel`. Each derivation is a logic class over a storage interface with a DBAL implementation [verified: src/Category, src/Cms, src/Promotion].

## Alternatives considered
Not documented.

## Consequences
Logic is unit-testable with static doubles; the SQL implementations are untested outside the leak check [verified]. Per-request caching only, N+1 queries for category owners [verified: ownership-sources.md].
