# AGENTS.md

## Project

This repository customizes OSPOS for small supermarket and fast-food deployments in Lebanon.

The priority is a working, maintainable solution delivered quickly. This is not an enterprise-grade rewrite.

## Current project status

- Approved baseline: `develop` snapshot `bcc9efc7c1ecf48273f03c5c0e3b24a8aef0c350`, application version 3.4.1, approved 2026-09-20.
- Phase 1 is complete and merged to `develop`: ADRs 0001 and 0002 are accepted and `docs/adr/gap-analysis.md` is complete.
- Phase 2 is complete and merged to `develop`: ADRs 0003 and 0004 are accepted, both Arabic locales are fully translated, the right-to-left layer is in place, and the layout was verified in Google Chrome in both directions.
- Operator documentation lives in `docs/`. Internal records, plans, and audits live in `docs/adr/`, which is untracked and stays on the developer machine. Track task status in `docs/adr/progress-checklist.md` and update it at the end of every phase.
- Phase 3 is complete and merged to `develop`: ADR 0005 is accepted, the TVA model and the Lebanese pound presentation are implemented, the migration was rehearsed and rolled back against a real database, and the new screens were verified in Google Chrome in both languages.
- The shop runs in Arabic (Lebanon) by default, the `admin` account runs in English, and the login page is always English and left to right. Decided 2026-09-20.
- Phase 4 is waiting for the project owner's hardware to arrive. Do not start model-specific work until the devices are named.

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
- Moved from `gpt-5.6-luna` and `gpt-5.6-sol` on 2026-09-23 at the project owner's request. Earlier reports and briefs that name the 5.6 models record what actually ran at the time and stay as they are.
- Forbid `ps`, `pgrep`, `pkill`, `kill` and `top` in every delegated prompt, because the implementation agent can match and kill its own process.
- The coordinating agent keeps audit, decision records, review, Git and GitHub operations, and is responsible for checking whatever the implementation agent returns before it is committed.
- Name the model that actually ran the work in the phase completion report. Do not record an intended model as if it had been used.

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
