# AGENTS.md

## Project

This repository customizes OSPOS for small supermarket and fast-food deployments in Lebanon.

The priority is a working, maintainable solution delivered quickly. This is not an enterprise-grade rewrite.

## Current project status

- Phase status lives in `docs/adr/progress-checklist.md`, which is untracked and stays on the developer machine. Read it for what is done and what is open, and update it at the end of every phase instead of adding status here.
- Approved baseline: `develop` snapshot `bcc9efc7c1ecf48273f03c5c0e3b24a8aef0c350`, application version 3.4.1, approved 2026-09-20.
- Operator documentation lives in `docs/`. Internal records, plans, and audits live in `docs/adr/`.
- The shop runs in Arabic (Lebanon) by default, the `admin` account runs in English, and the login page is always English and left to right. Decided 2026-09-20.
- Phase 4 is waiting for the project owner's hardware to arrive. Do not start model-specific work until the devices are named.
- Phase 6 production hardening is merged to `develop` (pull request #30, 2026-09-23): ADR 0009 is accepted, and the daily scheduled backup, keep-7 retention, optional USB copy, backup log, `127.0.0.1` binding, and warn-only `composer audit` job are in place, proved on Linux. Phase 6 is not complete: the Windows checks on the shop computer, the clean install and upgrade rehearsal, and automatic database migrations are still open.

## Code-style gate

- Continuous integration runs PHP-CS-Fixer over every PHP file changed in a pull request, and the configuration disagrees with most of the upstream tree.
- Touching one line of an upstream PHP file therefore requires reformatting that whole file, but the fixer configuration disables the strict-comparison and strict-parameter rules.
- Keep existing loose comparisons unless a change proves that a strict comparison is safe. Several upstream files carry `// TODO: ===` markers for exactly this reason.
- Prefer solving a problem with a CSS selector or a new file over editing an upstream view for one attribute.

## Source of truth

Read `docs/adr/OSPOS_IMPLEMENTATION_PLAN.md` before making changes. It is untracked, so it exists only on the developer machine.

Follow its phases, ADR numbering, scope, and acceptance criteria. If this file conflicts with the implementation plan, the implementation plan wins.

## Agent roles

- Hand implementation work to `gpt-6-luna`, run through the Codex command line: `codex exec -m gpt-6-luna -c model_reasoning_effort="xhigh"`.
- Hand review work to `gpt-6-sol`: `codex exec -m gpt-6-sol -c model_reasoning_effort="high"`.
- Pass `-m` and the reasoning effort every time. The local Codex default is `gpt-6-luna` at medium effort, so leaving them out silently runs the wrong model or a weaker setting.
- Close standard input (`< /dev/null`) or `codex exec` waits forever.
- Do not rewrite older reports that name the 5.6 models. They record what actually ran at the time.
- Forbid `ps`, `pgrep`, `pkill`, `kill` and `top` in every delegated prompt, because the implementation agent can match and kill its own process.
- The coordinating agent keeps audit, decision records, review, Git and GitHub operations, and is responsible for checking whatever the implementation agent returns before it is committed.
- Name the model that actually ran the work in the phase completion report. Do not record an intended model as if it had been used.
- The rules above are for the coordinating agent only. If you are `gpt-6-luna` or `gpt-6-sol` and were started by `codex exec`, you are the worker, not the coordinator.
- If you are `gpt-6-luna`, you are the implementer. Do the work yourself in this run. Do not start `codex exec`, another Codex session, or any sub-agent, and do not hand the task on to another model.
- If you are `gpt-6-sol`, you are the reviewer. Review the work yourself in this run and do not edit files. Do not start `codex exec`, another Codex session, or any sub-agent, and do not run your own review rounds.
- One run is one round. The implementer and the reviewer each finish their run with a report and stop. The coordinating agent decides whether another round is needed and starts it.

## Working directory

All repositories, documentation, ADRs, scripts, and project artifacts must remain under:

`/home/dev-hassanshd/hassan/pos`

Do not modify files outside this directory.

## Implementation principles

- Inspect existing OSPOS behavior before implementing anything.
- Prefer native configuration over custom code.
- Prefer small extensions over rewriting existing components.
- Keep customizations isolated to reduce future upgrade difficulty.
- Deliver the simplest implementation that satisfies the actual shop workflow.
- Do not introduce abstractions, services, queues, frameworks, or infrastructure without a current requirement.
- Do not optimize for hypothetical scale.
- Preserve English/LTR behavior while adding Arabic/RTL support.
- Preserve existing sales and historical transaction data.
- Never silently change tax, pricing, inventory, refund, or reporting behavior.

## Scope boundaries

Included:

- Arabic translations for operational screens.
- RTL and mixed Arabic/Latin content.
- Per-item TVA with an optional global default.
- Barcode scanner, receipt printer, and cash drawer support.
- Arabic receipt formatting.
- Touch-friendly fast-food ordering.
- Sizes, toppings, extras, removals, and combos.
- Kitchen ticket printing and routing.
- Backups, restore documentation, and basic production hardening.

Not included unless explicitly requested:

- Kitchen order-status tracking.
- Kitchen display systems.
- Dine-in/table management.
- Delivery management.
- Multi-branch architecture.
- Microservices.
- Mobile applications.
- PostgreSQL or SQLite migration away from the upstream-supported database.
- Large UI redesigns unrelated to the required workflows.

## ADRs

Create the ADR required by the implementation plan before implementing each numbered workstream.

Keep ADRs concise. They should document:

- Context.
- Existing OSPOS behavior.
- Decision.
- Alternatives considered.
- Consequences and risks.
- Test and rollback approach.

Use `Proposed` when user input is required and `Accepted` after approval.

Do not create additional ADRs for minor implementation details.

## User approval gates

Stop and ask the user before:

- Selecting or changing the upstream baseline release.
- Using a repository path not supplied by the user.
- Changing the database schema when native behavior may already satisfy the requirement.
- Choosing ambiguous TVA, rounding, exemption, or price-inclusion rules.
- Performing destructive database operations.
- Removing existing functionality.
- Pushing, merging, rebasing shared branches, or opening pull requests.
- Claiming hardware compatibility without real-device testing.

For ordinary implementation details inside an accepted ADR, proceed autonomously.

## Testing standard

The quality target is practical shop reliability, not exhaustive coverage.

Always test critical paths:

- Application startup and login.
- Creating and completing a sale.
- TVA calculations and rounding.
- Returns, voids, and discounts affected by changed code.
- Arabic and English interfaces.
- RTL and mixed-direction content.
- Receipt totals.
- Barcode input.
- Modifier and combo pricing.
- Kitchen ticket generation.
- Database migration and rollback when applicable.
- Backup restoration before production handoff.

Add focused automated tests for business logic. Use manual testing for UI layout, printers, scanners, and cash drawers.

Do not spend time increasing unrelated test coverage.

## Hardware

Do not assume a printer, scanner, or cash drawer works because it uses a common protocol.

Record hardware support as:

- `verified`: tested on the actual device.
- `expected`: technically compatible but not tested.
- `unsupported`: known not to work.

When hardware is unavailable, implement the software-side integration and clearly mark real-device validation as pending.

## Git

- `develop` is the integration branch, the repository's default branch since 2026-09-20, and the target of every pull request. `master` is dormant and is not used as a target.
- A pull request that conflicts with `develop` gets no checks at all, because GitHub cannot build the trial merge it runs them against. Merge `develop` into the phase branch before opening the pull request.
- One branch per phase, branched from `develop`. Implement the phase on it, open a pull request against `develop`, and leave the review and the merge to the project owner.
- Never merge a pull request. Never push to `develop` directly.
- Check the working tree before editing.
- Preserve user changes and unrelated modifications.
- Use small, focused commits.
- Follow existing repository conventions.
- Never commit secrets, databases, backups, customer data, generated dependencies, or environment-specific credentials.
- Never add AI attribution or `Co-Authored-By` trailers.
- Do not push unless explicitly requested.

Suggested branch prefixes:

- `feat/`
- `fix/`
- `docs/`
- `chore/`

## Docker images

- The development stack builds assets itself through the one-shot `assets` service.
- The shop image is published to Docker Hub as `hassanshamseddine/osposlb`. The local Docker client is already logged in to that account.
- Publishing is live: the `publish` job in `.github/workflows/main.yml` runs only on pushes to `develop` after all checks pass.
- The job pushes `hassanshamseddine/osposlb:develop`, which follows the newest `develop` commit, and `hassanshamseddine/osposlb:develop-<short sha>`, which stays fixed for pinning and rollback.
- Client machines pull the published image and never build it.
- Development still builds the image locally with `docker-compose.dev.yml`.
- Never build and push the image from a developer machine. Image publishing belongs to GitHub Actions only, so every published tag comes from a reviewed commit.
- The workflow authenticates with the repository secrets `DOCKER_HUB_USER` (the Docker Hub username) and `DOCKER_HUB` (the Docker Hub access token). Do not put either value in the repository, in a compose file, or in an ADR.
- Local Docker use is limited to building and running throwaway images for testing, and to the local development build.
- When a local test container bind-mounts a worktree over `/app`, the mount hides the installed dependencies that live inside the image. Add a separate `/app/vendor` volume to the service so the image's dependencies stay visible, or the command line tool and the web entry point both fail to start.

## Dependencies

- Reuse existing dependencies whenever practical.
- Avoid adding a dependency for functionality that can be implemented simply with the current stack.
- Pin dependencies according to existing project conventions.
- Document any new runtime or system dependency.
- Do not upgrade unrelated dependencies during feature work.

## Documentation

Update documentation only when it helps installation, operation, testing, backup, restoration, hardware setup, or future maintenance.

Do not create speculative or duplicate documentation.

## Completion report

At the end of each phase, report:

1. What changed.
2. ADRs created or updated.
3. Tests executed and their results.
4. Hardware behavior that remains unverified.
5. Migrations and rollback instructions.
6. Known limitations.
7. The recommended next phase.

Keep reports concise and factual.
