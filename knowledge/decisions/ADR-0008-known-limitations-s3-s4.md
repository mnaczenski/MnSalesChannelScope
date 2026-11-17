---
id: ADR-0008
status: accepted
scope: scope-core
created: 2026-11-17
source: user decision after /onboard (verified findings S3, S4)
---
# Role self-escalation and roles left without channels stay known, documented limitations

## Context
Verified in a test shop on 2026-11-17 (docs/system/overview.md):
- S3: a limited user given `acl_role:create` and `acl_user_role:create` (no preset grants them) can create a role without channels, assign it to themselves and become unrestricted.
- S4: deleting a role's last sales channel deletes its mapping rows (`ON DELETE CASCADE`); a role without channels counts as "all channels", so the role becomes unrestricted. Shopware refuses to delete channels with orders, so this concerns channels without orders.

## Decision
Not fixed. Both are documented as known limitations in README.md ("Limitations") and docs/architecture.md ("Limitations"), with the workaround (don't grant role management to limited users; adjust roles before deleting a channel).

## Alternatives considered
- Refuse role, user and role-assignment writes for limited users (S3).
- Lock instead of unlock a role whose channel list becomes empty, e.g. no cascade or a guard on channel deletion (S4).

## Consequences
Both follow from the default-open scope model (ADR-0002, proposed). If the model changes to default-closed, revisit this decision.
