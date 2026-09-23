Install the pinned browser package with `npm install` from this folder.
Run `npm run qa -- --base http://127.0.0.1:18098 --mysql-container osposqa-mysql-1` against the disposable QA app.
Set `CHROME_PATH` to the Chrome executable path when it is not `/usr/bin/google-chrome`.
Use `--only ITEM-01,SALE-*` to choose scenarios, `--out out/` to choose the output root, and `--compare path/to/results.json` to compare timings.
The script applies settings and creates the cashier through the UI before verifying setup and item fixtures through MySQL.
The optional MySQL container enables SQL verification and per-scenario slow query capture.
Each run creates a timestamped folder under the selected output root.
The folder contains `results.json`, `summary.md`, screenshots, slow query logs, and any CSV fixtures created by scenarios.
`pass` means every value named by that scenario was checked and matched its expected value.
`fail` means a checked value or required app action did not match the expected result.
`finding` means the observed app behavior needs review or the scenario cannot fully prove its goal.
`blocked` means a required fixture or earlier scenario was missing, so the scenario could not run.
