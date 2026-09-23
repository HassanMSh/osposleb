import fs from "node:fs/promises";
import path from "node:path";

/** Write complete JSON evidence and a compact Markdown run report. */
export async function writeReport(out, base, scenarios, stamp, comparison = null) {
    const statuses = ["pass", "fail", "finding", "blocked"];
    const totals = Object.fromEntries(
        statuses.map((status) => [status, scenarios.filter((row) => row.status === status).length]),
    );
    const requests = scenarios.flatMap((scenario) =>
        scenario.requests.map((request) => ({ ...request, scenario: scenario.id })),
    );
    const slow = requests
        .filter((request) => !request.injectedDelay)
        .sort((a, b) => b.durationMs - a.durationMs)
        .slice(0, 20);
    const baselineSql = scenarios.find((scenario) => scenario.id === "SETUP")?.sql?.repeated || [];
    const baseline = new Map(baselineSql.map((row) => [row.statement, row.count]));
    const repeated = scenarios
        .filter((scenario) => scenario.id !== "SETUP")
        .flatMap((scenario) =>
            (scenario.sql?.repeated || []).map((row) => ({
                ...row,
                baselineCount: baseline.get(row.statement) || 0,
                scenario: scenario.id,
            })),
        )
        .sort((a, b) => b.count - a.count)
        .slice(0, 20);
    const rows = scenarios
        .map((scenario) => {
            const slowRequest = scenario.slowestRequest
                ? `${scenario.slowestRequest.method} ${scenario.slowestRequest.path}` +
                  ` (${scenario.slowestRequest.durationMs}ms)`
                : "—";
            const note =
                scenario.note ||
                (typeof scenario.actual === "string" ? scenario.actual : JSON.stringify(scenario.actual)) ||
                "";
            const cells = [
                scenario.id,
                scenario.status,
                scenario.clicks,
                scenario.scannerChars,
                scenario.typedKeys,
                scenario.requests.length,
                scenario.fullNavigations,
                scenario.dialogCount,
                `${scenario.focusChecks.filter(Boolean).length}/${scenario.focusChecks.length}`,
                slowRequest,
                scenario.sql?.count ?? "—",
                note.replaceAll("|", "\\|").slice(0, 150),
            ];
            return `| ${cells.join(" | ")} |`;
        })
        .join("\n");
    const findings =
        scenarios
            .filter((scenario) => ["fail", "finding"].includes(scenario.status))
            .map((scenario) => {
                const detail = scenario.note || JSON.stringify(scenario.actual);
                const evidence = JSON.stringify(scenario.actual).slice(0, 500);
                return `- ${scenario.id}: ${detail}; evidence=${evidence}`;
            })
            .join("\n") || "- None.";
    const blocked =
        scenarios
            .filter((scenario) => scenario.status === "blocked")
            .map((scenario) => `- ${scenario.id}: ${scenario.blockedBy || "none"} — ${scenario.note}`)
            .join("\n") || "- None.";
    const sqlRows =
        repeated
            .map(
                (row) =>
                    `| ${row.scenario} | ${row.baselineCount} | ${row.count} |` +
                    ` ${row.statement.slice(0, 180).replaceAll("|", "\\|")} |`,
            )
            .join("\n") || "| — | no repeated SQL in slow log | — | — | — |";
    const slowRows =
        slow
            .map(
                (row) =>
                    `| ${row.scenario} | ${row.method} ${row.path} | ${row.durationMs} |` +
                    ` ${row.status} | ${row.bytes} |`,
            )
            .join("\n") || "| — | no dynamic requests | — | — | — |";
    const staticCount = scenarios.reduce((sum, scenario) => sum + scenario.staticAssets.count, 0);
    const staticBytes = scenarios.reduce((sum, scenario) => sum + scenario.staticAssets.bytes, 0);
    const navigations = scenarios.reduce((sum, scenario) => sum + scenario.fullNavigations, 0);
    const staticAverage = navigations ? Math.round(staticBytes / navigations) : 0;
    const content = [
        `# QA run ${stamp}`,
        "",
        `Totals: ${JSON.stringify(totals)}`,
        "",
        "| ID | Status | Clicks | Scan chars | Typed keys | Reqs | Navs | Dialogs | Focus | Slowest | SQL | Note |",
        "|---|---|---:|---:|---:|---:|---:|---:|---:|---|---:|---|",
        rows,
        "",
        "## Slowest requests",
        "",
        "| Scenario | Request | ms | Status | Bytes |",
        "|---|---|---:|---:|---:|",
        slowRows,
        "",
        "## Most repeated SQL",
        "",
        "| Scenario | Baseline count | Scenario count | SQL shape |",
        "|---|---:|---:|---|",
        sqlRows,
        "",
        "## Static assets",
        "",
        "Static asset requests are excluded from request counts and slowest request tables.",
        "",
        "| Total requests | Total bytes | Average bytes per full page load |",
        "|---:|---:|---:|",
        `| ${staticCount} | ${staticBytes} | ${staticAverage} |`,
        "",
        "## Failures and findings",
        "",
        findings,
        "",
        "## Blocked scenarios",
        "",
        blocked,
        "",
        "## Run comparison",
        "",
        comparison || "No prior reference run provided.",
        "",
    ].join("\n");
    await fs.writeFile(
        path.join(out, "results.json"),
        JSON.stringify({ base, timestamp: stamp, totals, scenarios }, null, 2),
    );
    await fs.writeFile(path.join(out, "summary.md"), content);
    return totals;
}
