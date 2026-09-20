# Project documentation

This directory holds the documentation written for this OSPOS fork. It is tracked in Git.

Upstream OSPOS once generated API documentation into `docs/` and therefore ignored the whole directory in `.gitignore`. That generator is no longer part of the project, so the ignore rule was removed and this directory is now used for fork documentation only.

Upstream files outside this directory are left as the original developers wrote them. Do not move upstream documentation such as `README.md`, `INSTALL.md`, `BUILD.md`, `UPGRADE.md`, `CHANGELOG.md`, `SECURITY.md`, or `CODE_OF_CONDUCT.md` into this directory.

## Layout

- `OSPOS_IMPLEMENTATION_PLAN.md` — the phased execution contract for this fork. It is the source of truth for scope, phases, and acceptance criteria.
- `progress-checklist.md` — one place to see what is done, blocked, or pending across all phases.
- `gap-analysis.md` — the Phase 1 audit of native OSPOS behavior and the disposition of every project requirement.
- `adr/` — architecture decision records, numbered `0001` through `0009`, one per workstream defined in the implementation plan.

Accepted records so far:

- `adr/0001-upstream-release-and-baseline.md` — the approved upstream release and how the fork is pinned to it.
- `adr/0002-native-feature-configuration-and-gap-analysis.md` — the audit method and how every requirement was routed to a phase.
- `adr/0003-arabic-translation-ownership-and-terminology.md` — who owns each Arabic locale, the terminology rules, and how completeness is enforced.
- `adr/0004-rtl-and-mixed-direction.md` — how right-to-left layout is applied without changing English, and how identifiers and numbers stay readable inside Arabic text.

Later phases add `test-plan.md`, `backup-and-restore.md`, and `operations-runbook.md` to this directory.

## Conventions

- One ADR per numbered workstream. ADR numbering is stable and is never reassigned.
- An ADR starts as `Proposed` and becomes `Accepted` only after the project owner approves it.
- Record evidence with concrete file references so a finding can be reproduced against the approved baseline.
- Never record secrets, credentials, customer data, or database contents here.
- Do not soft-wrap lines. Keep each sentence or bullet on one line, except inside fenced code blocks.
