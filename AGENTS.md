# Agent Instructions

This document provides guidance for AI agents working on this OSPOS fork.

## Current project status

- Phase 0 was completed and user-approved on 2026-09-20.
- Approved baseline: `develop`/`origin/develop` snapshot `bcc9efc7c1ecf48273f03c5c0e3b24a8aef0c350` (application version 3.4.1).
- Repository path: `/home/dev-hassanshd/hassan/pos/osposleb`.
- Project documentation: `/home/dev-hassanshd/hassan/pos/osposleb/docs`; implementation plan: `docs/OSPOS_IMPLEMENTATION_PLAN.md`.
- Phase 1 is in progress on branch `chore/ospos-baseline`: ADRs 0001 and 0002 are accepted and `docs/gap-analysis.md` is complete.
- Track task status in `docs/progress-checklist.md` and update it at the end of every phase.
- Next phase: Phase 2 — Arabic localization and RTL support.

## Phase workflow

- Before starting a new implementation phase, ask the user for approval to create the phase branch, commit, push, and open its pull request.
- After approval, start the phase from the latest `origin/develop` and use a focused branch.
- Keep phase commits small and push the phase branch to `origin`.
- Open the phase pull request against `develop`.
- Do not merge until every required pull-request check passes and the user explicitly approves the merge.
- Do not push, open a pull request, or merge without the required user approval.

## Code style

- Follow PSR-12 and CodeIgniter 4 conventions.
- Run PHP-CS-Fixer with `.php-cs-fixer.no-header.php` before committing PHP changes.
- Use `camelCase` for variables and methods, `PascalCase` for classes, and `UPPER_CASE` for constants.
- Write code compatible with PHP 8.1 and later unless an accepted ADR changes the supported runtime.
- Import classes, functions, and constants with `use` statements instead of inline fully qualified names.
- Add comments only when they explain non-obvious behavior or constraints.
- Use `const` for JavaScript variables that are not reassigned and `let` for variables that are. Do not use `var`.

## Testing

- Run PHPUnit with `composer test` when PHP tests exist for the changed behavior.
- Add focused tests for changed business logic.
- All required pull-request checks must pass before merging into `develop`.
- Use one test file per class under test. Add new cases to the class's existing test file when it exists.

## Continuous integration

- Do not build application container images during the baseline and configuration stage.
- Keep the application container-build step commented out until application code changes require it and the user approves enabling it.
- Do not publish images, packages, or releases from pull-request workflows.

## Build

- Install dependencies with `composer install` and `npm install`.
- Build assets with `npm run build` or `gulp`.

## Conventions

- Put controllers in `app/Controllers/`.
- Put models in `app/Models/`.
- Put views in `app/Views/`.
- Put database migrations in `app/Database/Migrations/`.
- Sanitize input and escape output with the `esc()` helper.

## Localization

- Add new language keys to all `app/Language/*/` variants in alphabetical order.
- Use an empty string in a non-English language only when no translation is available, so CodeIgniter can use its fallback.
- When explicitly asked to translate text, provide the real translation rather than an empty or English value.
- Preserve English/LTR behavior when changing localization or layout.

## Security

- Never commit secrets, credentials, `.env` files, databases, backups, or customer data.
- Use parameterized queries.
- Validate and sanitize user input.
