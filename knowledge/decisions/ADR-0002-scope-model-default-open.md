---
id: ADR-0002
status: proposed
scope: scope-core
created: 2026-11-17
source: /onboard
---
# Scope model: limited only if every role has channels; everything else unrestricted

## Context
Existing roles and users must keep working when the plugin is installed [inferred].

## Decision
A user is limited when every role has sales channels; their channels are the union. Admins, integrations, system scope, users without roles, users with one role without channels, and roles whose channel list became empty are unrestricted [verified: src/Scope/ChannelScope.php:29-80, docs/architecture.md "Who is limited"].

## Alternatives considered
Default closed: a limited user stays limited when a role loses its channels or an unscoped role is added.

## Consequences
Installing changes nothing for existing users. But privilege changes can silently lift a limit: verified S3 (self-assigned role without channels) and S4 (deleted last channel) [verified]. Integrations can't be limited.
