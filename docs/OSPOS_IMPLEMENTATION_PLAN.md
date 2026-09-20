# OSPOS Phased Implementation Plan

## Purpose

This document is the execution contract for a Codex or Claude coding agent implementing the OSPOS project.

All project work, including the repository checkout, ADRs, supporting documentation, scripts, and local artifacts, must live under:

```text
/home/dev-hassanshd/hassan/pos
```

The user will provide the exact local repository path after the agent recommends an upstream OSPOS release to fork. Do not clone, fork, initialize, or modify a repository before that approval and path are provided.

## Operating rules

1. Work phase by phase. Do not implement later phases early.
2. At the start of each phase, inspect the relevant upstream code and existing native OSPOS behavior before proposing changes.
3. Prefer configuration and small extensions over unnecessary forks of core behavior.
4. Preserve upstream compatibility and make future rebases/upgrades practical.
5. Never commit secrets, credentials, production data, customer data, or generated backups.
6. Use feature branches and small, reviewable commits. Do not push, merge, or open a pull request unless explicitly requested.
7. Do not add AI attribution or `Co-Authored-By` trailers to commits.
8. Do not claim hardware support without testing it or clearly marking it as unverified.
9. Every phase must have documented acceptance criteria and evidence of verification.
10. If a requirement conflicts with native OSPOS behavior or would require a major architectural change, stop and present options, risks, and the recommended decision.

## Required project structure

Once the repository path is supplied, maintain at least this structure under the parent directory:

```text
/home/dev-hassanshd/hassan/pos/
├── <repository-path-provided-by-user>/
└── docs/
    ├── adr/
    │   ├── 0001-upstream-release-and-baseline.md
    │   ├── 0002-native-feature-configuration-and-gap-analysis.md
    │   ├── 0003-arabic-translations.md
    │   ├── 0004-rtl-and-mixed-direction-support.md
    │   ├── 0005-tva-tax-model.md
    │   ├── 0006-pos-hardware-integration.md
    │   ├── 0007-arabic-receipt-formatting.md
    │   ├── 0008-fast-food-workflow.md
    │   └── 0009-production-readiness.md
    ├── gap-analysis.md
    ├── test-plan.md
    ├── backup-and-restore.md
    └── operations-runbook.md
```

If the repository already has an established documentation convention, the agent may place `docs/` inside the repository instead, but every resulting path must remain beneath `/home/dev-hassanshd/hassan/pos`. Record the chosen layout in ADR 0001.

## ADR requirements

Create one ADR for each numbered workstream before implementing that workstream. An ADR may begin as `Proposed` and become `Accepted` only after the user approves any material product or architecture choice.

Every ADR must include:

- Title, status, date, and decision owners.
- Context and current upstream/native behavior, supported by code references.
- Requirements and explicit non-goals.
- Options considered.
- Decision and rationale.
- Data-model, migration, compatibility, and rollback impact where applicable.
- Security, privacy, performance, localization, hardware, and operational impact where applicable.
- Test and acceptance criteria.
- Consequences, risks, and follow-up work.
- Links to the implementation branch, commits, tests, and upstream documentation when available.

ADR numbering is stable. Add supplementary ADRs only when a genuinely separate cross-cutting decision is needed; do not renumber the nine required ADRs.

## Phase 0 — Upstream release recommendation and repository handoff

### Goal

Choose the safest maintainable OSPOS baseline without making repository changes.

### Agent tasks

1. Inspect the official OSPOS repository, release notes, tags, supported runtime versions, database requirements, installation documentation, open upgrade warnings, and recent maintenance activity.
2. Compare the latest stable release with the immediately preceding maintained stable release when relevant.
3. Exclude prereleases unless there is a documented, compelling requirement.
4. Recommend one exact immutable release tag and record:
   - Release/tag and commit SHA.
   - Release date and maintenance status.
   - Required PHP, database, web-server, Node/build-tool, and browser versions.
   - Known migration or compatibility risks.
   - Why it is preferable for this project.
5. Present the recommendation to the user and stop.

### Required user gate

Ask the user to provide:

- The exact local path of the fork/repository beneath `/home/dev-hassanshd/hassan/pos`.
- Confirmation of the selected upstream tag.

Do not create ADR 0001, modify files, install dependencies, or start implementation until both are provided.

## Phase 1 — Fork baseline and native capability audit

### Covers

- TODO 1: Fork and baseline a stable OSPOS release.
- TODO 2: Configure native features and identify actual gaps.

### Agent tasks

1. Validate that the supplied path is beneath the required parent directory and points to the intended fork.
2. Verify remotes, selected tag/commit, working-tree state, and licensing. Do not overwrite existing work.
3. Create ADR 0001 for the upstream release and baseline strategy.
4. Build and run the unmodified baseline using the repository-supported workflow.
5. Create ADR 0002 covering native configuration and the gap-analysis method.
6. Inventory and test native capabilities relevant to this project, including:
   - Existing Arabic locale coverage.
   - RTL behavior.
   - Tax configuration, per-item taxes, and global/default taxes.
   - Barcode input.
   - Receipt printing and cash-drawer triggering.
   - Item kits/combos, variants, attributes, modifiers, and sales-screen usability.
   - Reporting, users/permissions, backup/restore, and update behavior.
7. Configure capabilities already supported natively before designing custom code.
8. Produce `docs/gap-analysis.md` with one of these dispositions per requirement: `native/configuration`, `small extension`, `custom feature`, `unsupported/deferred`, or `requires hardware validation`.

### Exit criteria

- Baseline starts successfully and its exact version is reproducible.
- ADRs 0001 and 0002 are accepted.
- Native configuration is committed separately from custom features.
- Gap analysis identifies the actual implementation scope and any changes to later phases.
- Automated baseline tests pass, or pre-existing failures are documented without being disguised as regressions.

## Phase 2 — Arabic localization foundation

### Covers

- TODO 3: Add Arabic translations for operational screens.
- TODO 4: Add full RTL and mixed-direction support.

### Agent tasks

1. Create ADR 0003 for Arabic translation ownership, terminology, fallback behavior, and upstream contribution strategy.
2. Define the operational-screen scope, at minimum: login, register/sales, items, inventory, customers, payments, returns, reports used by operators, errors, validation messages, and printer-facing labels.
3. Reuse and correct upstream Arabic resources before adding new translation mechanisms.
4. Add translation completeness checks where practical; avoid hard-coded user-facing strings.
5. Create ADR 0004 for RTL implementation and mixed-direction rules.
6. Implement RTL without breaking LTR locales. Explicitly handle Arabic mixed with:
   - Numbers, decimals, currencies, percentages, and TVA values.
   - SKUs, barcodes, phone numbers, emails, URLs, and timestamps.
   - Latin product names and operator-entered free text.
7. Test desktop and touch-sized layouts, dialogs, tables, navigation, forms, and printed/previewed output.

### Exit criteria

- Operational screens meet the agreed Arabic coverage target.
- Locale switching does not require code changes.
- RTL and LTR both pass targeted regression tests.
- Mixed-direction identifiers remain readable and copyable.
- Screenshots or equivalent visual evidence cover the critical workflows.

## Phase 3 — TVA model

### Covers

- TODO 5: Implement per-item TVA with an optional global default percentage.

### Agent tasks

1. First confirm the extent of native OSPOS tax support discovered in Phase 1.
2. Create ADR 0005 covering:
   - Whether native tax tables/configuration can satisfy the requirement.
   - Per-item override semantics.
   - Optional global default and the precedence rule.
   - Tax-inclusive versus tax-exclusive prices.
   - Tax-exempt items, zero-rated items, rounding, precision, returns, discounts, and historical transactions.
   - Receipt and reporting behavior.
   - Schema/data-migration and rollback strategy, if any.
3. Obtain user approval for ambiguous business rules before changing the schema or calculation logic.
4. Implement the smallest compatible change and add calculation-focused automated tests.

### Default precedence to evaluate, not assume

```text
explicit per-item TVA -> global default TVA -> no TVA
```

The ADR must explicitly decide how an item opts out of a global default; `unset/inherit`, `zero-rated`, and `tax-exempt` must not be silently conflated.

### Exit criteria

- Existing transactions and existing tax configuration remain valid.
- New sale, return, discount, reporting, and receipt paths calculate TVA consistently.
- Rounding behavior is deterministic and tested.
- Migration and rollback are tested against a backup copy, never the only database.

## Phase 4 — POS hardware and Arabic receipts

### Covers

- TODO 6: Configure and test barcode scanner, receipt printer, and cash drawer.
- TODO 7: Improve Arabic receipt formatting.

### Agent tasks

1. Create ADR 0006 covering supported connection modes, browser/host responsibilities, printer protocol/driver assumptions, cash-drawer triggering, deployment topology, and fallback behavior.
2. Document the exact hardware models, interfaces, OS, browser, drivers, character/code-page support, paper width, and test status. Ask the user for missing hardware details before model-specific implementation.
3. Treat a typical USB barcode scanner as keyboard input unless the confirmed device requires a different integration.
4. Test barcode scan focus, speed, terminator handling, unknown codes, duplicates, and Arabic keyboard/layout interaction.
5. Test receipt printing, reprinting, failure handling, and the cash drawer. Prevent the drawer from opening for unauthorized actions or inappropriate payment types.
6. Create ADR 0007 covering Arabic receipt rendering. Evaluate text/code-page output versus rasterized/server-rendered output based on confirmed printer capabilities.
7. Test Arabic shaping, RTL alignment, mixed Arabic/Latin lines, numerals, currency, TVA breakdowns, long product names, modifiers, totals, and 58/80 mm widths as applicable.

### Exit criteria

- A hardware compatibility matrix identifies `verified`, `expected`, and `unsupported` combinations.
- Real-device acceptance is completed for each supplied device; otherwise the phase is explicitly marked as awaiting hardware validation.
- Test prints are legible and totals match the application.
- Drawer behavior follows the accepted security and payment rules.
- Setup and troubleshooting steps are documented.

## Phase 5 — Fast-food workflow

### Covers

- TODO 8: Add a fast-food workflow:
  - Touch-friendly product/menu selection.
  - Sizes, toppings, extras, and removal modifiers.
  - Combos/item kits.
  - Kitchen ticket printing/routing.

### Explicit non-goal

No kitchen order-status system is planned. Do not implement order queues, preparation states, kitchen display status, ready/served tracking, or dine-in/take-away workflow unless separately approved later.

### Agent tasks

1. Create ADR 0008 after validating which parts can use native item kits, variants, attributes, categories, or printer behavior.
2. Define a minimal domain model for:
   - Base menu item.
   - Required and optional modifier groups.
   - Sizes.
   - Paid and free extras.
   - Removal modifiers such as “no onion.”
   - Minimum/maximum selections and duplicate quantities.
   - Combo/item-kit pricing and inventory effects.
3. Define deterministic pricing, tax, discount, refund, void, receipt, reporting, and inventory behavior for modifiers and combos.
4. Implement a touch-friendly sales flow with large targets, low interaction count, visible selected modifiers, and fast correction/void behavior.
5. Implement kitchen ticket printing/routing only. Define routing by a simple accepted rule such as category or printer destination, with reprint and printer-failure handling.
6. Keep customization isolated enough to allow upstream upgrades.

### Exit criteria

- A cashier can create, correct, pay, void, refund, and reprint representative fast-food orders.
- Prices, TVA, inventory, reports, customer receipt, and kitchen ticket agree.
- Kitchen tickets clearly show quantities and modifier deltas.
- A printer failure is visible and recoverable without silently losing a ticket.
- No kitchen order-status feature has been introduced.

## Phase 6 — End-to-end validation and production hardening

### Covers

- TODO 9: Run end-to-end testing, backups, documentation, and production hardening.

### Agent tasks

1. Create ADR 0009 covering deployment topology, environment configuration, backup design, restore objectives, update strategy, security controls, observability, and support boundaries.
2. Produce `docs/test-plan.md` containing automated and manual scenarios for:
   - Arabic and English operation.
   - LTR, RTL, and mixed-direction content.
   - Tax calculations and historical transactions.
   - Standard retail and fast-food sales.
   - Payments, suspended sales if supported, returns, voids, discounts, refunds, and reprints.
   - Barcode scanner, printer, drawer, and kitchen routing.
   - Permissions and destructive/privileged actions.
   - Network, browser, printer, database, and restart failure cases.
3. Create automated database backups with retention and off-machine/off-site copies appropriate to the final deployment.
4. Produce `docs/backup-and-restore.md` and perform a restore drill into an isolated environment. Record recovery time and validation results.
5. Harden production configuration: secrets, least privilege, TLS where networked, session/cookie settings, dependency scanning, supported runtime versions, log hygiene, database access, and update/rollback procedures.
6. Produce `docs/operations-runbook.md` for startup, shutdown, health checks, backup checks, restore, upgrades, printer troubleshooting, log collection, and common incidents.
7. Run the full test suite and a clean installation/upgrade rehearsal.

### Exit criteria

- Critical end-to-end workflows pass on the intended deployment and hardware.
- Backup creation is monitored and a restore drill has succeeded.
- No critical/high known security issue remains without explicit documented acceptance.
- Installation, update, rollback, and operator procedures are reproducible.
- Remaining risks and deferred items are listed with owners and priorities.

## Recommended branch and delivery sequence

Use the repository's naming convention when one exists. Otherwise use:

```text
chore/ospos-baseline
docs/native-gap-analysis
feat/arabic-localization
feat/rtl-support
feat/tva-model
feat/pos-hardware
feat/arabic-receipts
feat/fast-food-workflow
chore/production-hardening
```

Keep ADRs with the phase they govern. Separate pure configuration, schema migrations, application logic, localization, UI, and operational documentation into reviewable commits where practical.

## Required phase report

At the end of every phase, report concisely:

1. Status: completed, blocked, or awaiting approval.
2. ADRs created or updated and their status.
3. Files and behavior changed.
4. Tests run, exact results, and any untested hardware-dependent behavior.
5. Migrations and rollback path.
6. Known risks, deferred work, and upstream-upgrade impact.
7. The next proposed phase and any user decision required.

## First prompt for the implementation agent

```text
Read this plan completely and begin Phase 0 only. Inspect the official OSPOS
project and recommend one exact stable release tag to fork, with its commit SHA,
runtime/database requirements, maintenance status, known compatibility risks,
and concise rationale. Do not clone, fork, create ADRs, install dependencies, or
modify files yet. Stop after the recommendation and ask me for confirmation of
the tag and the exact local repository path beneath
/home/dev-hassanshd/hassan/pos.
```
