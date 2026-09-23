# Operator documentation

This directory holds the documentation an operator needs to install, run, and maintain this OSPOS fork. It is tracked in Git.

Upstream OSPOS once generated API documentation into `docs/` and therefore ignored the whole directory in `.gitignore`. That generator is no longer part of the project, so the ignore rule was removed and this directory is now used for fork documentation only.

Upstream files outside this directory are left as the original developers wrote them. Do not move upstream documentation such as `README.md`, `INSTALL.md`, `BUILD.md`, `UPGRADE.md`, `CHANGELOG.md`, `SECURITY.md`, or `CODE_OF_CONDUCT.md` into this directory.

## Layout

- `backup-and-restore.md` — daily backups, manual backups, and restore steps for Windows and Linux.
- `operations-runbook.md` — startup, checks, updates, support logs, and install tasks.
- `test-plan.md` — critical workflow checks and recorded hardening proofs.
- `qa-scenarios.md` — cashier item and sale scenarios, and how to run the browser QA suite in `tests/qa-e2e/`.
- `windows-client-install.md` — step-by-step checklist for a new Windows shop computer, including the Chrome shortcuts for silent receipt printing, with the problems found on the first run.

Later phases may add hardware setup notes to this directory.

## Internal records

Decision records, the implementation plan, the progress checklist, the gap analysis, and the working audits live in `docs/adr/`. That directory is deliberately untracked and never committed. It is written for whoever implements the fork, not for the operator running the shop, so it is kept on the developer's machine only.

## Conventions

- Document only what helps installation, operation, testing, backup, restoration, hardware setup, or future maintenance.
- Never record secrets, credentials, customer data, or database contents here.
- Do not soft-wrap lines. Keep each sentence or bullet on one line, except inside fenced code blocks.
