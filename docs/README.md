# Project documentation

This directory holds the documentation written for this OSPOS fork. It is tracked in Git.

Upstream OSPOS once generated API documentation into `docs/` and therefore ignored the whole directory in `.gitignore`. That generator is no longer part of the project, so the ignore rule was removed and this directory is now used for fork documentation only.

Upstream files outside this directory are left as the original developers wrote them. Do not move upstream documentation such as `README.md`, `INSTALL.md`, `BUILD.md`, `UPGRADE.md`, `CHANGELOG.md`, `SECURITY.md`, or `CODE_OF_CONDUCT.md` into this directory.

## Layout

- `OSPOS_IMPLEMENTATION_PLAN.md` — the phased execution contract for this fork. It is the source of truth for scope, phases, and acceptance criteria.
- `progress-checklist.md` — one place to see what is done, blocked, or pending across all phases.
- `gap-analysis.md` — the Phase 1 audit of native OSPOS behavior and the disposition of every project requirement.
- `adr/` — architecture decision records, numbered `0001` through `0009`, one per workstream defined in the implementation plan.

Later phases add `test-plan.md`, `backup-and-restore.md`, and `operations-runbook.md` to this directory.

## Conventions

- One ADR per numbered workstream. ADR numbering is stable and is never reassigned.
- An ADR starts as `Proposed` and becomes `Accepted` only after the project owner approves it.
- Record evidence with concrete file references so a finding can be reproduced against the approved baseline.
- Never record secrets, credentials, customer data, or database contents here.
- Do not soft-wrap lines. Keep each sentence or bullet on one line, except inside fenced code blocks.
