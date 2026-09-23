import { chromium } from "playwright-core";
import fs from "node:fs/promises";
import path from "node:path";
import { spawnSync } from "node:child_process";
import { makeBrowser } from "./lib/browser.mjs";
import { attachMeasurements, beginScenario, endScenario } from "./lib/measure.mjs";
import { sql } from "./lib/sql.mjs";
import { writeReport } from "./lib/report.mjs";
import { setup } from "./scenarios/setup.mjs";
import { itemScenario } from "./scenarios/items.mjs";
import { saleScenario } from "./scenarios/sales.mjs";
import { perfScenario } from "./scenarios/perf.mjs";

const args = process.argv.slice(2);
function option(name, fallback) {
    const index = args.indexOf(name);
    return index >= 0 ? args[index + 1] : fallback;
}
const base = option("--base", "http://127.0.0.1:18098").replace(/\/$/, "");
const mysqlContainer = option("--mysql-container", "");
const stamp = new Date().toISOString().replace(/[:.]/g, "-");
const out = path.resolve(option("--out", "out/"), stamp);
const selection = option("--only", "");
await fs.mkdir(path.join(out, "screenshots"), { recursive: true });
await fs.mkdir(path.join(out, "slowlog"), { recursive: true });
const chromiumBrowser = await chromium.launch({
    executablePath: process.env.CHROME_PATH || "/usr/bin/google-chrome",
    headless: true,
});
const contexts = new Map();
let active = null;
for (const role of ["admin", "cashier"]) {
    const browserContext = await chromiumBrowser.newContext();
    const page = await browserContext.newPage();
    instrumentLocators(page);
    contexts.set(role, { browserContext, page });
    attachMeasurements(page, () => active);
}
const ctx = {
    base,
    mysqlContainer,
    out,
    get page() {
        return contexts.get(this.role || "admin").page;
    },
    set page(value) {
        this._page = value;
    },
    role: "admin",
    measure: () => active,
    quote: (value) => `'${String(value).replaceAll("'", "''")}'`,
    ui: null,
    async login(username, password) {
        this.role = username === "admin" ? "admin" : "cashier";
        const current = contexts.get(this.role);
        const page = current.page;
        if (
            (page.url().includes("/sales") && username !== "admin") ||
            (page.url().includes("/config") && username === "admin")
        )
            return;
        await page.goto(`${base}/login`, { waitUntil: "domcontentloaded", timeout: 25000 });
        if (await page.locator("input[name=username]").count()) {
            await page.locator("input[name=username]").fill(username);
            await page.locator("input[name=password]").fill(password);
            await Promise.all([
                page.waitForLoadState("domcontentloaded").catch(() => {}),
                page.locator("button[type=submit],input[type=submit]").first().click(),
            ]);
        }
    },
    execSql(query) {
        const result = spawnSync(
            "docker",
            ["exec", mysqlContainer, "mysql", "-uroot", "-ppointofsale", "ospos", "-N", "-B", "-e", query],
            { encoding: "utf8", maxBuffer: 30 * 1024 * 1024 },
        );
        return { ok: result.status === 0, output: result.stdout?.slice(0, 500) || result.stderr };
    },
    sqlItems() {
        const result = sql(mysqlContainer, "SELECT item_number,name FROM ospos_items WHERE deleted=0 ORDER BY item_id");
        return (result.rows || []).map((row) => {
            const [barcode, name] = row.split("\t");
            return { barcode, name };
        });
    },
};
ctx.ui = makeBrowser(
    {
        get locator() {
            return ctx.page.locator.bind(ctx.page);
        },
        get keyboard() {
            return ctx.page.keyboard;
        },
        get url() {
            return ctx.page.url;
        },
        on: (...args) => ctx.page.on(...args),
        goto: (...args) => ctx.page.goto(...args),
        locator: (...args) => ctx.page.locator(...args),
    },
    () => active,
);
// Keep wrappers and Playwright page methods pointed at the selected role.
ctx.ui = {
    async click(locator) {
        await locator.click({ timeout: 12000 });
    },
    async fill(locator, value) {
        await locator.fill(String(value), { timeout: 12000 });
    },
    async press(locator, key) {
        await locator.press(key, { timeout: 12000 });
    },
    async goto(url) {
        return ctx.page.goto(url, { waitUntil: "domcontentloaded", timeout: 25000 });
    },
    async focusCheck() {
        if (active) {
            const item = ctx.page.locator("#item");
            active.focusChecks.push(
                (await item.count())
                    ? await item.evaluate((el) => document.activeElement === el).catch(() => false)
                    : false,
            );
        }
    },
};

/** Track scanner characters, typed keys, and click actions made through Playwright locators. */
function instrumentLocators(page) {
    const wrap = (locator) =>
        new Proxy(locator, {
            get(target, property) {
                const value = target[property];
                if (typeof value !== "function") return value;
                if (["click", "check", "uncheck", "selectOption"].includes(property))
                    return async (...args) => {
                        if (active) active.clicks++;
                        return value.apply(target, args);
                    };
                if (property === "fill")
                    return async (text, ...args) => {
                        if (active) {
                            const characters = String(text).length;
                            if (active.scannerInput) active.scannerChars += characters;
                            else active.typedKeys += characters;
                            active.keys = active.scannerChars + active.typedKeys;
                        }
                        return value.call(target, text, ...args);
                    };
                if (property === "press")
                    return async (key, ...args) => {
                        if (active && !active.scannerInput) {
                            active.typedKeys++;
                            active.keys = active.scannerChars + active.typedKeys;
                        }
                        return value.call(target, key, ...args);
                    };
                if (property === "pressSequentially" || property === "type")
                    return async (text, ...args) => {
                        if (active) {
                            active.typedKeys += String(text).length;
                            active.keys = active.scannerChars + active.typedKeys;
                        }
                        return value.call(target, text, ...args);
                    };
                return (...args) => {
                    const result = value.apply(target, args);
                    return result &&
                        typeof result === "object" &&
                        typeof result.click === "function" &&
                        typeof result.fill === "function"
                        ? wrap(result)
                        : result;
                };
            },
        });
    const originalLocator = page.locator.bind(page);
    page.locator = (...args) => wrap(originalLocator(...args));
}

const allIds = [
    "SETUP",
    ...Array.from({ length: 40 }, (_, i) => `ITEM-${String(i + 1).padStart(2, "0")}`),
    ...Array.from({ length: 50 }, (_, i) => `SALE-${String(i + 1).padStart(2, "0")}`),
    ...Array.from({ length: 5 }, (_, i) => `PERF-${String(i + 1).padStart(2, "0")}`),
];
const patterns = selection ? selection.split(",") : allIds;
const selected = allIds.filter((id) =>
    patterns.some((pattern) => (pattern.endsWith("*") ? id.startsWith(pattern.slice(0, -1)) : id === pattern)),
);
if (!selected.includes("SETUP")) selected.unshift("SETUP");
const results = [];
for (const id of selected) {
    if (id.startsWith("ITEM-") || id.startsWith("SALE-") || id.startsWith("PERF-")) {
        active = null;
        ctx.role = "cashier";
        await ctx.login("qacashier", "QACashier2026").catch(() => {});
        await ctx.ui.goto(`${base}/sales`).catch(() => {});
        const cart = ctx.page.locator('#register_wrapper form[id^="cart_"]');
        const cancel = ctx.page.locator("#cancel_sale_button");
        if ((await cart.count()) && (await cancel.count())) {
            await cancel.click().catch(() => {});
            await ctx.page.waitForTimeout(180);
            await ctx.ui.goto(`${base}/sales`).catch(() => {});
        }
    }
    active = beginScenario(id, mysqlContainer);
    const measure = active;
    let outcome;
    try {
        if (id === "SETUP") {
            ctx.role = "admin";
            outcome = await setup(ctx);
        } else if (id.startsWith("ITEM-")) {
            ctx.role = "cashier";
            outcome = await itemScenario(ctx, Number(id.slice(5)));
        } else if (id.startsWith("SALE-")) {
            ctx.role = "cashier";
            outcome = await saleScenario(ctx, Number(id.slice(5)));
        } else {
            ctx.role = "cashier";
            outcome = await perfScenario(ctx, Number(id.slice(5)));
        }
        if (!outcome.status) outcome.status = "finding";
    } catch (error) {
        outcome = {
            status: "fail",
            expected: "Scenario completes through its described UI or SQL path",
            actual: `${error.name}: ${error.message}`,
            note: "Runner exception. This is a script failure to fix before treating it as an app finding.",
        };
        await ctx.page.screenshot({ path: path.join(out, "screenshots", `${id}.png`), fullPage: true }).catch(() => {});
    }
    await endScenario(measure, outcome, { mysqlContainer, out });
    if (measure.status === "fail" || id === "ITEM-12" || id === "SALE-01")
        await ctx.page.screenshot({ path: path.join(out, "screenshots", `${id}.png`), fullPage: true }).catch(() => {});
    results.push(measure);
    active = null;
    console.log(`${id} ${measure.status} ${measure.wallTimeMs}ms`);
}
const priorPath = option("--compare", "");
let comparison = null;
if (priorPath) {
    try {
        const previous = JSON.parse(await fs.readFile(path.resolve(priorPath), "utf8"));
        const old = new Map(previous.scenarios.map((s) => [s.id, s]));
        comparison =
            results
                .map((s) => {
                    const p = old.get(s.id);
                    if (!p) return null;
                    const diff = s.wallTimeMs - p.wallTimeMs;
                    if (Math.abs(diff) <= 0.3 * p.wallTimeMs) return null;
                    const sign = diff > 0 ? "+" : "";
                    const percent = ((diff / p.wallTimeMs) * 100).toFixed(1);
                    return `- ${s.id}: ${p.wallTimeMs}ms → ${s.wallTimeMs}ms ` + `(${sign}${diff}ms, ${percent}%)`;
                })
                .filter(Boolean)
                .join("\n") || "No scenario differs by more than 30%.";
    } catch {
        comparison = "Prior result file could not be read.";
    }
}
const totals = await writeReport(out, base, results, stamp, comparison);
await chromiumBrowser.close();
console.log(JSON.stringify({ out, totals }, null, 2));
