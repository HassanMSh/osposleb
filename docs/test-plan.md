# Production phase test plan

Run these checks before a production handoff.

The detailed cashier scenarios for items and sales are in `qa-scenarios.md`, and `tests/qa-e2e/` runs them in a browser.

The Linux results for ADR 0009 are listed below and recorded in the local evidence folder.

## Critical paths

| Critical path | Test method | Status | How to test |
| --- | --- | --- | --- |
| Startup and login | Manual | verified | Start the client stack and sign in from Chrome. |
| Create and complete a sale | Manual | verified | Sell an item for cash and confirm it appears in sales history. |
| F12 completes a sale | Automated browser check and manual | verified | Scan an item and press F12: it adds the cash payment, or completes when Complete is shown; a double press sends one request, and F12 does nothing with an empty cart, an open dialog, or in quote mode. |
| TVA and rounding | Automated PHPUnit and manual | verified | Set an item TVA rate and compare sale and receipt totals with the expected rounded amount. |
| Returns, voids, and discounts | Manual | expected | Return, void, and discount a test sale and compare the totals and reports. |
| Arabic and English screens | Manual | verified | Sign in with each language and complete a sale in both. |
| RTL and mixed-direction text | Manual | verified | Open Arabic screens in Chrome and check names, numbers, and barcodes. |
| Receipt totals | Manual | verified | Compare a TVA sale total with the customer receipt. |
| Barcode input | Hardware | pending | Scan known and unknown item barcodes with the named shop scanner. |
| Modifier and combo pricing | Manual | verified | Sizes, combos, extras, and removals are plain items with their own price (ADR 0008), so there is no modifier logic to test; ring REST-03 in `qa-scenarios.md` and check the total is the sum of the lines. |
| Kitchen ticket generation | Automated PHPUnit, manual, and hardware | expected | Run the restaurant scenarios in `qa-scenarios.md`; the ticket content and reprint are verified in Chrome, and the cut between receipt and ticket on the T80A is still to test on the real printer (#154). |
| Database migration and rollback | Manual | verified | Rehearse the migration on a database copy and restore the saved copy. |
| Backup restoration | Manual | verified | Restore the latest archive into a scratch database and uploads folder. |

## ADR 0009 proofs

### Linux

- 2026-09-23 retention and failure: pass; keep seven archives, preserve unrelated items, and leave archives unchanged after failure; see `01-retention-and-failure.txt`.
- 2026-09-23 USB copy: pass; empty copy setting had no warning, SHA-256 matched, keep seven applied, and missing or unwritable folders warned while backup succeeded; see `02-usb-copy.txt`.
- 2026-09-23 run log: pass; thirteen runs produced thirteen lines with all eight fields; see `03-log-format.txt`.
- 2026-09-23 Docker unavailable: pass; exit code was non-zero and the host wrote `message=Docker_is_not_running`; see `04-docker-unreachable.txt`.
- 2026-09-23 local binding: pass; the only listener was `127.0.0.1`, a request from this computer got an HTTP answer, and the LAN address was refused; the answer was a 500 error that the upstream image also returns from inside its own container, so it is not caused by the binding; see `05-localhost-binding.txt` and `05b-localhost-binding-rerun.txt`.
- 2026-09-23 Composer audit: pass; the real and fake audit scripts exited 0, the fake audit printed a warning, and the workflow YAML parsed; see `06-composer-audit.txt`.
- 2026-09-23 PowerShell checks: pass; both scripts parsed, scheduling stubs confirmed replacement and removal, wait polling reached the backup stub, and backup options were passed to Docker; see `07-powershell-scheduling.txt`.
- 2026-09-23 shell and ad-hoc backup: pass; Bash syntax and ShellCheck passed, ad-hoc backup kept all seeded archives, and old or overridden client settings worked; see `08-shell-and-ad-hoc.txt`.
- 2026-09-23 restore drill: pass; the newest archive restored 27 tables and uploads into a scratch target; see `09-restore-drill.txt`.
- 2026-09-23 same-second clash: pass; a run that stopped because an archive with its timestamp already existed kept that archive and logged the failure; the version before the fix deleted it; see `10-same-second-archive.txt`.
- 2026-09-23 review fixes: pass; a missing `ospos.conf` logged a failure line from both launchers, a container failure with an unusual exit code logged exactly one line and exited 1, and both PowerShell scripts parsed; see `11-fix-round.txt` and `12-coordinator-final-fixes.txt`.
- 2026-09-23 fix round: pass; client override archives use the configured client log, no-client runs log beside the archive, launcher failures add one line, container start failures record the exit code, read-only logs stop before retention, keep seven preserved three unrelated items, and a failed success-log append warned and kept its archive; Bash syntax, ShellCheck, both PowerShell parses, and workflow YAML parsing passed; see `11-fix-round.txt`.
- 2026-09-23 client pictures folder (D-011): pass; a fresh client setup created `uploads/item_pics/`, Settings returned HTTP 200, an item picture and its thumbnail loaded, restoring an archive without `item_pics` recreated it, restoring an archive with pictures kept them readable, and no hidden restore folders were left; see `qa-evidence/d011-item-pics-2026-09-23/`.

### Windows

- Scheduled task registration, replacement, removal, and missed-run catch-up: expected until tested on the shop computer.
- Windows backup launcher, Docker wait, and host failure logging: expected until tested on the shop computer.
- USB copy with Docker Desktop file sharing: expected until tested with the shop drive.
- Windows restore drill and recovery time: expected until tested on the shop computer.
- Windows client setup and restore creating `uploads/item_pics/`: expected until tested on the shop computer.
