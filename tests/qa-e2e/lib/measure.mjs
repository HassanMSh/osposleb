import fs from "node:fs/promises";
import path from "node:path";
import { spawnSync } from "node:child_process";
import { summarizeSql } from "./sql.mjs";

const staticTypes = new Set(["stylesheet", "script", "font", "image"]);

/** Attach request, error, dialog, navigation, and static asset measurements. */
export function attachMeasurements(page, getMeasurement) {
    const owners = new WeakMap();
    page.on("request", (request) => {
        const m = getMeasurement();
        if (!m) return;
        if (new URL(request.url()).pathname === "/sales/add") m.addRequestCount++;
        const postKeys = request.postData() ? [...new URLSearchParams(request.postData()).keys()] : [];
        owners.set(request, {
            m,
            started: Date.now(),
            method: request.method(),
            url: request.url(),
            type: request.resourceType(),
            document: request.isNavigationRequest() && request.resourceType() === "document",
            postKeys,
            injectedDelay: !!m.injectedDelayPath && new URL(request.url()).pathname === m.injectedDelayPath,
        });
    });
    page.on("response", async (response) => {
        const data = owners.get(response.request());
        if (!data) return;
        const url = new URL(data.url);
        let bytes = 0;
        try {
            bytes = (await response.body()).length;
        } catch {}
        const row = {
            method: data.method,
            path: url.pathname + url.search,
            status: response.status(),
            durationMs: Date.now() - data.started,
            bytes,
            fullNavigation: data.document,
            resourceType: data.type,
            injectedDelay: data.injectedDelay,
            ...(data.postKeys.length ? { postKeys: data.postKeys } : {}),
        };
        if (response.status() >= 500) row.errorBody = (await response.text().catch(() => "")).slice(0, 1000);
        if (staticTypes.has(data.type)) {
            data.m.staticAssets.count++;
            data.m.staticAssets.bytes += bytes;
        } else data.m.requests.push(row);
        if (response.status() >= 400) data.m.failures.push({ kind: "http", status: response.status(), path: row.path });
    });
    page.on("requestfailed", (request) => {
        const data = owners.get(request);
        if (data)
            data.m.failures.push({
                kind: "request",
                path: new URL(data.url).pathname,
                error: request.failure()?.errorText,
            });
    });
    page.on("console", (message) => {
        if (message.type() === "error" && getMeasurement()) getMeasurement().consoleErrors.push(message.text());
    });
    page.on("pageerror", (error) => {
        if (getMeasurement()) getMeasurement().pageErrors.push(error.message);
    });
    page.on("dialog", async (dialog) => {
        if (getMeasurement()) getMeasurement().dialogs.push(dialog.type());
        await dialog.accept();
    });
}

/** Start a scenario and isolate its slow query log. */
export function beginScenario(id, mysqlContainer) {
    const measure = {
        id,
        clicks: 0,
        keys: 0,
        scannerChars: 0,
        typedKeys: 0,
        addRequestCount: 0,
        requests: [],
        pending: [],
        failures: [],
        dialogs: [],
        consoleErrors: [],
        pageErrors: [],
        focusChecks: [],
        staticAssets: { count: 0, bytes: 0 },
        started: Date.now(),
    };
    if (mysqlContainer) {
        const cleared = spawnSync("docker", ["exec", mysqlContainer, "sh", "-c", ": > /var/lib/mysql/slow.log"], {
            encoding: "utf8",
        });
        if (cleared.status !== 0) measure.sqlUnavailable = cleared.stderr || "slow log clear failed";
    }
    return measure;
}

/** Finish a scenario with timing and SQL data, while keeping SQL optional. */
export async function endScenario(measure, outcome, context) {
    measure.wallTimeMs = Date.now() - measure.started;
    Object.assign(measure, outcome);
    measure.dialogCount = measure.dialogs.length;
    measure.fullNavigations = measure.requests.filter((request) => request.fullNavigation).length;
    measure.staticAssets.bytesPerPageLoad = measure.fullNavigations
        ? Math.round(measure.staticAssets.bytes / measure.fullNavigations)
        : 0;
    measure.slowestRequest =
        measure.requests.filter((request) => !request.injectedDelay).sort((a, b) => b.durationMs - a.durationMs)[0] ||
        null;
    if (context.mysqlContainer && !measure.sqlUnavailable) {
        const copied = spawnSync("docker", ["exec", context.mysqlContainer, "cat", "/var/lib/mysql/slow.log"], {
            encoding: "utf8",
            maxBuffer: 40 * 1024 * 1024,
        });
        if (copied.status === 0) {
            const file = path.join(context.out, "slowlog", `${measure.id}.log`);
            await fs.writeFile(file, copied.stdout);
            measure.sql = summarizeSql(copied.stdout);
        } else measure.sqlUnavailable = copied.stderr || "slow log read failed";
    }
}
