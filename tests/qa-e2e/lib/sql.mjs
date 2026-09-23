import { spawnSync } from "node:child_process";

/** Run a read-only SQL query against the optional QA database container. */
export function sql(container, query) {
    if (!container) return { ok: false, error: "MySQL container was not supplied" };
    const result = spawnSync(
        "docker",
        ["exec", container, "mysql", "-uroot", "-ppointofsale", "ospos", "-N", "-B", "-e", query],
        { encoding: "utf8", maxBuffer: 20 * 1024 * 1024 },
    );
    return result.status === 0
        ? { ok: true, rows: result.stdout.trim().split("\n").filter(Boolean) }
        : { ok: false, error: result.stderr || result.error?.message };
}

/** Parse MySQL slow-log query count, duration, top statements, and repeats. */
export function summarizeSql(log) {
    const chunks = log.split(/# Time:/).slice(1);
    const queries = chunks.map((chunk) => {
        const statement =
            chunk
                .split(/\n/)
                .filter((line) => !/^\s*SET (?:timestamp|SESSION)\b/i.test(line))
                .join("\n")
                .match(/\b(?:SELECT|INSERT|UPDATE|DELETE|SHOW|CALL)\b[\s\S]*/i)?.[0]
                ?.replace(/\s+/g, " ")
                .trim() || "";
        return {
            timeMs: Number((chunk.match(/Query_time: ([\d.]+)/) || [])[1] || 0) * 1000,
            statement,
        };
    });
    const normalize = (value) =>
        value
            .replace(/'[^']*'|\b\d+(?:\.\d+)?\b/g, "?")
            .replace(/\s+/g, " ")
            .toLowerCase();
    const repeats = new Map();
    for (const query of queries) {
        const shape = normalize(query.statement);
        if (shape) {
            const row = repeats.get(shape) || { statement: shape, baselineCount: 0, scenarioCount: 0 };
            row.scenarioCount++;
            repeats.set(shape, row);
        }
    }
    return {
        count: queries.length,
        totalTimeMs: queries.reduce((sum, query) => sum + query.timeMs, 0),
        top: [...queries].sort((a, b) => b.timeMs - a.timeMs).slice(0, 5),
        repeated: [...repeats.values()].map((row) => ({ ...row, count: row.scenarioCount })),
    };
}
