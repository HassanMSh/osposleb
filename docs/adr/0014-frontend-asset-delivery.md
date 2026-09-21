# ADR 0014: Front-end asset delivery, and the UI breakage it caused

- Status: Accepted
- Date: 2026-09-21
- Decision owners: Project owner and implementation team
- Scope: How built front-end assets reach a running instance, plus triage of the UI defects reported on 2026-09-21
- Baseline: `develop` at `214f49308`, application version 3.4.1
- Branch: `fix/frontend-asset-delivery`

## Context and current behaviour

On 2026-09-21 the project owner reported that the interface is visibly broken on `localhost:18090`, in both Arabic and English. Twenty-three separate defects were listed across the navigation bar, the home page, the sales register, the items page, the printed receipt, and the Arabic wording.

Most of them are one fault wearing twenty different faces.

### How the page asks for its assets

The shared page header declares the stylesheet and the script bundle by name, and those names carry a content hash:

```html
<link rel="stylesheet" href="resources/opensourcepos-d68d923d5f.min.css">
<script src="resources/jquery-2c872dbe60.min.js"></script>
<script src="resources/opensourcepos-39c74204a5.min.js"></script>
```

The header file is tracked in Git. The files it names are not:

```
.gitignore:4:  public/resources
.gitignore:5:  public/images/menubar/*
```

The asset build writes both. It concatenates thirteen project stylesheets and every plug-in stylesheet into the one hashed bundle, does the same for the scripts, copies the nineteen module icons out of a Node dependency into `public/images/menubar/`, copies the Bootswatch themes into `public/resources/bootswatch/`, and then rewrites the tracked header file in place with the new hashes.

So the repository commits the *references* and ignores the *files*. Any checkout that has not had the build run against it serves a page that asks for a stylesheet, a script bundle, and nineteen icons that are not on disk.

### Confirmed on disk

| Location | Built stylesheets and scripts | Module icons |
| --- | --- | --- |
| `osposleb` (main checkout) | 57 present, all 55 header references resolve | 19 present |
| `wt-adr0010` | 0 | 0 |
| `wt-adr0011` | 0 | 0 |
| `wt-arabic-wording` | 0 | 0 |
| `wt-shop-lockdown-browser-qa` | 0 | 0 |

Every worktree is a bare checkout. Every worktree serves a broken page.

The development compose file bind-mounts the checkout over the whole application directory:

```yaml
    volumes:
        - .:/app
```

That mount hides anything the image had built, which is the same trap already recorded in `AGENTS.md` for the dependency directory. The remedy used there — a separate volume for the hidden path — was never applied to the asset directories.

The container image never builds assets either. The `Dockerfile` copies the tree and installs PHP extensions. It never runs the Node install or the asset build. This was recorded as an open Phase 1 finding and has not been addressed: *"The application container image does not install PHP dependencies and does not run the asset build. A production image built from this repository as-is will not run."*

### Why the navigation bar still looked styled

The theme stylesheet is requested at an unhashed, stable path:

```html
<link rel="stylesheet" href="resources/bootswatch/flatly/bootstrap.min.css">
```

Depending on which instance was running, that path can resolve from the image while the hashed bundle beside it does not, which is exactly the half-styled page that was reported: Bootstrap's own rules present, everything the project adds absent.

### What the missing bundle contains

Losing the one stylesheet loses all of this at once:

- `bootstrap-select` — the pretty drop-down. Without its stylesheet the native `<select>` is never hidden, so both render side by side.
- `bootstrap-table` — the grid. Without it the loading overlay is never styled or hidden, the toolbar collapses, and headers stop lining up with rows.
- `ospos.css` — the navigation bar, the home grid, and the list styling that stops `<ul>` items showing bullets.
- `register.css` — the two-column label/value layout of the totals and the payment block.
- `ospos_print.css` — the entire print stylesheet, which already hides the top bar, the menu bar, the footer, and every `print_hide` element.
- `ospos_rtl.css` — the whole Phase 2 right-to-left layer, 10.3 KB of it.

Losing the script bundle loses jQuery UI, the select picker, and the table plug-in, so nothing that is supposed to initialise on page load does.

### Defect triage

Of the twenty-three reported defects, sixteen are direct consequences of the assets never arriving:

| Reported | Cause |
| --- | --- |
| 1. Broken module icons in the navigation bar | Icons not on disk |
| 2. Home page renders as a list of broken images | Icons not on disk, plus the missing grid stylesheet |
| 4. Footer overflows in Arabic, misaligned in English | Missing project stylesheet and missing right-to-left layer |
| 5. Stray list bullets | Missing project stylesheet |
| 6. Drop-downs render twice | Missing select-picker stylesheet and script |
| 7. Search label overlaps the input | Missing project stylesheet |
| 8. Cart table misaligned | Missing table stylesheet |
| 9. Totals have no gap between label and value | Missing register stylesheet |
| 10. Payment block misaligned | Missing register stylesheet |
| 12. Loading message never disappears | Missing table stylesheet and script |
| 13. Filter multiselect renders twice | Same as 6 |
| 14. Stray "..." below the toolbar | Missing table stylesheet |
| 15. Toolbar spacing wrong | Missing table stylesheet |
| 16. Page chrome is printed | Missing print stylesheet |
| 20. Receipt columns do not line up | Missing receipt stylesheet |
| Part of 19 (right-to-left faults) | Missing right-to-left layer |

Two reported causes are **not** correct and need no work:

- **Defect 3, the base address.** The base address is derived at runtime from the request host, so the port is picked up automatically. Port 18090 is not the problem.
- **Defect 11, the pound rounding.** `2.50 × 89,500 = 223,750`, and the approved rule rounds the displayed pound figure to the nearest 5,000, which gives 225,000. This is correct as designed. No change unless the owner wants to change the rule.

Five are real and survive an asset fix:

- **Defect 10, the doubled "المبلغ المدفوع".** Not a duplicated field. The two payment inputs in the register view sit in mutually exclusive branches, so only one ever renders. What is duplicated is the *label*. Phase 3 added a change helper whose "tendered amount" label was translated to the same four words as the payment field's "amount tendered": both read `المبلغ المدفوع`. In English they differ ("Tendered amount" against "Amount Tendered"); in Arabic they are identical. This is ours, introduced in Phase 3.
- **Defect 17, link addresses printed in parentheses.** Bootstrap's own print rules append `content: " (" attr(href) ")"` after every link. Those rules come from the theme stylesheet, which loads fine, so this survives the asset fix. The project print stylesheet has no override.
- **Defect 18, receipt paper geometry.** There is no `@page` rule anywhere in the project. One item spills onto a second sheet.
- **Defect 19, receipt text direction.** Minus signs on the wrong side, the pound total wrapping onto two lines, a misplaced full stop, and two headers running together.
- **Defect 21, hardcoded English on the register.** The stock annotation is built in the view by string concatenation with an English word in the middle: `'[' . to_quantity_decimals($item['in_stock']) . ' in ' . $item['stock_name'] . ']'` at `app/Views/sales/register.php:171`, and the same pattern at `app/Views/receivings/receiving.php:140`. It can never translate. The two image alt texts are hardcoded English in the same way.

Defects 22 and 23 — two words for "item", and Egyptian spelling in Lebanese strings — are already logged in `docs/adr/arabic-wording-qa-findings.md` and belong to that log, not here.

### A separate finding, discovered while investigating

`docker-compose.yml` — the file the client setup builds on — runs `image: jekkos/opensourcepos:master`. That is upstream Open Source Point of Sale. It contains none of this fork's Arabic, right-to-left, tax, pound, or lockdown work. `AGENTS.md` states the shop image is `hassanshamseddine/osposlb`, and no workflow publishes that image; `.github/workflows/main.yml` has syntax, coding-standards, and test jobs only, and its image-build step is commented out.

This is not what caused the reported breakage — the reporter clearly had the fork's Arabic and pound work on screen — but a client installed from the current compose file would get upstream, not this fork.

## Requirements and non-goals

Required:

- A running instance must always have the assets its own page markup asks for, whether it was started from the image, from the development compose file, or from a worktree during review.
- The failure must be loud. Today it is silent: the page returns 200 and merely looks wrong.
- The fix must use the build that already exists. No new bundler, no new pipeline, no hand-written replacement stylesheets.
- The right-to-left layer must be proven to load, because six of the reported faults are simply that layer being absent.

Non-goals:

- Changing the asset build itself, its hashing, or its plug-in list.
- Reworking the print layout for real paper. That is hardware work.
- Changing the pound rounding rule.
- Resolving the Arabic wording items already tracked in the wording log.

## Options considered

**A. Commit the build output to Git.** Un-ignore the two directories and check in the bundles and icons.

Works everywhere with no build step, and a bare clone runs. But every rebuild changes the content hash, so each one rewrites the tracked header file and replaces a 1.5 MB script bundle and a 115 KB stylesheet in the history. It conflicts on every merge, it diverges from upstream's own ignore rules, and it makes the repository heavy for no lasting benefit. Rejected.

**B. Build the assets inside the container image.** Add a Node stage to the `Dockerfile` that installs the Node dependencies and runs the build, then carry the result into the application stage.

This is the smallest change that makes the problem structurally impossible. The build already exists and already works; it simply never runs where the application is assembled. It also fixes the standing Phase 1 finding that an image built from this repository will not run. It costs image build time and adds Node to the build, though not to the shipped layer. Preferred.

**C. Document a pre-flight step.** Tell whoever starts an instance to run the build first.

Costs nothing and changes nothing. It also already failed: the step is documented in `BUILD.md` and the interface still shipped broken to the owner's screen. A checklist is not a mechanism. Rejected on its own, kept as supporting documentation.

**D. A guard that fails loudly.** A check that reads the asset names out of the header file and asserts every one exists on disk.

Cheap, fast, needs no database or browser, and turns a silent visual failure into a named failing test. It does not fix anything by itself. Adopted alongside B.

## Decision

1. **Build the front-end assets inside the container image.** Add a Node build stage to the `Dockerfile` that installs the Node dependencies and runs the existing build, and copy `public/resources`, `public/images/menubar`, and the rewritten page header into the application stage. Do not commit build output to Git.

2. **Require a development or review checkout to build its own assets, and restore only the dependency directory from the image.** Add a volume for `/app/vendor` to the development compose file, as `AGENTS.md` already prescribes.

   This reverses the first draft of this decision, which also added volumes for `/app/public/resources` and `/app/public/images/menubar`. Review showed that approach reintroduces the very fault this ADR exists to remove. The bind mount supplies the page header from the checkout while the volume would supply the assets from the image. The asset filenames are content hashes that must match that header, so the two can disagree, and they disagree silently. A named volume also populates only when it is first created, so an image rebuild leaves stale assets behind a header that now asks for new hashes.

   The dependency directory has no such coupling — nothing references it by hash — so restoring it from the image is safe and stays.

   An unbuilt checkout therefore renders unstyled, and the check in decision 3 says so in plain words. That is the intended outcome: a loud, accurate failure beats a quiet, plausible one.

3. **Add an asset-integrity check to the test suite.** Parse the asset references out of the page header and assert each file exists. Assert specifically that the right-to-left stylesheet and the print stylesheet are inside the bundle, since those two carry work this fork depends on. Assert that each injection block actually declares assets, so an emptied block fails rather than passing with nothing to check.

   The continuous integration test job must build the front-end assets before running the suite. It checks out a bare tree, which by definition has none, so without that step this check fails on every run. Building there also proves the asset build itself still works on every change.

4. **Point the client compose file at this fork's image.** Replace the upstream image reference with `hassanshamseddine/osposlb`, and add the publishing workflow that `AGENTS.md` already requires, authenticating with the existing repository secrets. Treat the missing workflow as part of this work, because the decision above is worthless if the shop still pulls upstream.

5. **Fix the five real defects that survive the asset fix**, except the two that belong to hardware:
   - Give the change helper's tendered label its own Arabic wording, distinct from the payment field.
   - Override Bootstrap's print rule so link addresses are not printed.
   - Replace the hardcoded English in the stock annotation and the two image alt texts with language strings, in English and both Arabic locales.

6. **Defer receipt paper geometry and receipt text direction to ADR 0007, Phase 4.** The `@page` rule, the 80 mm width, the minus-sign placement, the wrapping pound total, and the receipt column alignment all need a real printer and a real roll to judge. ADR 0007 is already reserved for Arabic receipt rendering, and the checklist already carries receipt direction as a deliberate Phase 2 deferral. This report is the evidence that it must be done, not a reason to guess at it now.

7. **Make no change to the pound rounding.** It behaves as approved.

## Consequences and risks

- The image gains a Node build stage, so image builds get slower and need network access to the Node registry. The shipped layer does not carry Node.
- Running the build rewrites the tracked page header with fresh hashes, so a local build will show that file as modified. Inside the image the header and the files change together, so they stay consistent. The committed hashes are a snapshot, not a contract, and this should be stated in `BUILD.md`.
- The asset-integrity check will fail on a bare checkout until the build has been run there. That is the intended behaviour: it is the loud failure the current setup lacks. The check must state plainly that the remedy is to run the build.
- Switching the client compose file to this fork's image is a change to what a shop actually installs. It needs a published image to exist first, so the workflow must land before or with the compose change.
- Six of the reported right-to-left faults are expected to disappear once the layer loads. If any of them survive, they are genuine layout bugs that the missing stylesheet was masking, and they will need their own work. This is the main uncertainty in this ADR.
- The wording items stay open and still need a native Lebanese Arabic reviewer.

## Compatibility, migration, and rollback

No database change. No schema change. No migration.

English and left-to-right behaviour is unaffected: the stylesheet that returns is the same one that was verified in Chrome during Phase 2.

Rollback is to revert the branch. Nothing persists outside the image and the compose files.

## Test and acceptance criteria

1. The asset-integrity check passes against a built tree and fails against a bare one, with a message that names the missing files.
2. The existing suite still passes: 75 tests, 341 assertions.
3. An instance started from the image serves every asset the page references, with zero 404 responses in the network log, confirmed on the register, the items page, the home page, and a receipt.
4. An instance started from the development compose file against a worktree does the same, proving the mount no longer hides the assets.
5. Defects 1, 2, 5, 6, 7, 8, 9, 10, 12, 13, 14, 15 and 20 are re-checked in Chrome in both languages and confirmed gone.
6. The right-to-left layer is confirmed loaded, and the six Phase 2 pages are compared against the Phase 2 evidence.
7. Printing a receipt shows no top bar, no header, no buttons, and no link addresses in parentheses. Paper geometry is explicitly out of scope and stays open.
8. The two tendered labels read differently on the register in Arabic.
9. The stock annotation renders from a language string in both Arabic locales.
10. English is confirmed unchanged on the same pages.

## Test and rollback approach

Automated: the asset-integrity check plus the existing suite, run in the container after a clean rebuild.

Manual: Chrome driven directly against a rebuilt instance, in English and Lebanese Arabic, per the browser review method already used in Phases 2 and 3. Printing is checked with the browser's print preview; real paper is Phase 4.

Rollback: revert the branch. No data is touched.

## Links and evidence

- Owner's defect report, 2026-09-21, twenty-three items.
- `.gitignore` lines 4 and 5: the two ignored asset directories.
- `app/Views/partial/header.php`: hashed references, tracked; the injection markers the build writes between.
- `gulpfile.js` lines 207-215 and 239-247: the stylesheet bundle members, including the print and right-to-left layers; line 263 onward: the module icon copy.
- `Dockerfile`: before this change, no Node install and no asset build.
- `docker-compose.dev.yml`: before this change, `- .:/app` with no dependency volume.
- `docker-compose.yml`: `image: jekkos/opensourcepos:master`.
- `.github/workflows/main.yml`: no image publishing job; the build step is commented out at lines 95-98.
- `app/Language/ar-LB/Sales.php` lines 10 and 24: the two identical labels.
- `app/Views/sales/register.php` line 171 and `app/Views/receivings/receiving.php` line 140: the hardcoded English stock annotation.
- `public/css/ospos_print.css`: already hides the chrome; no Bootstrap link-address override, no `@page` rule.
- `docs/progress-checklist.md`, Phase 1 findings: the image build gap, recorded and still open.
- `docs/adr/arabic-wording-qa-findings.md`: where defects 22 and 23 belong.
