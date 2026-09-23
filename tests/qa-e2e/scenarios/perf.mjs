import { sql } from "../lib/sql.mjs";
import { summarizeSql } from "../lib/sql.mjs";
import fs from "node:fs/promises";
import path from "node:path";
import { spawnSync } from "node:child_process";

/** Seed catalog data or measure cashier register performance journeys. */
export async function perfScenario(ctx, number) {
    const { page, ui, base } = ctx;
    if (number === 1) {
        const schema = sql(ctx.mysqlContainer, "SHOW COLUMNS FROM ospos_items");
        if (!schema.ok)
            return {
                status: "fail",
                expected: "SQL seed access",
                actual: schema.error,
                note: "PERF-01 requires the configured MySQL container.",
            };
        const escaped = (value) => value.replaceAll("'", "''");
        const clearQuantities = ctx.execSql(
            `DELETE FROM ospos_item_quantities
                WHERE item_id IN (SELECT item_id FROM ospos_items WHERE item_number LIKE 'PF%')`,
        );
        const clearItems = clearQuantities.ok
            ? ctx.execSql("DELETE FROM ospos_items WHERE item_number LIKE 'PF%'")
            : clearQuantities;
        if (!clearItems.ok)
            return {
                status: "fail",
                expected: "Old PERF fixtures cleared before the 5,000 row seed",
                actual: clearItems.output,
                note: "Could not clear prior performance rows.",
            };
        const location = sql(
            ctx.mysqlContainer,
            "SELECT location_id FROM ospos_stock_locations ORDER BY location_id LIMIT 1",
        );
        if (!location.rows?.length)
            return {
                status: "fail",
                expected: "At least one stock location",
                actual: "No stock location returned by SQL",
                note: "Cannot seed searchable catalog with stock quantities.",
            };
        let seed = { ok: true, output: "inserted 0" };
        for (let start = 0; start < 5000; start += 250) {
            const values = Array.from({ length: Math.min(250, 5000 - start) }, (_, offset) => {
                const i = start + offset;
                const label = i % 2 ? `PERF حليب ${i}` : `PERF Milk ${i}`;
                return (
                    `('${escaped(label)}','Performance','',1,1,0,1,0,0,0,0,0,1,` +
                    `'exempt',1,'','PF${String(i).padStart(10, "0")}')`
                );
            });
            seed = ctx.execSql(
                `INSERT INTO ospos_items (name, category, description, cost_price, unit_price,
                    reorder_level, receiving_quantity, allow_alt_description, is_serialized,
                    deleted, stock_type, item_type, taxable, tax_exemption_reason,
                    qty_per_pack, hsn_code, item_number) VALUES ${values.join(",")}`,
            );
            if (!seed.ok) break;
        }
        if (seed.ok)
            seed = ctx.execSql(
                `INSERT INTO ospos_item_quantities (item_id, location_id, quantity)
                    SELECT item_id, ${location.rows[0]}, 100 FROM ospos_items
                    WHERE item_number LIKE 'PF%'`,
            );
        await ctx.login("qacashier", "QACashier2026");
        await ui.goto(`${base}/sales`);
        const queries = [];
        for (const term of ["PERF 12", "حليب", "PF0000001"]) {
            const url = `${base}/sales/itemSearch?term=${encodeURIComponent(term)}`;
            const measured = await measureSearch(ctx, url, `PERF-01-${queries.length + 1}`);
            queries.push({ term, ...measured });
        }
        const measuredList = await measureSearch(
            ctx,
            `${base}/items/search?search=&offset=0&limit=25`,
            "PERF-01-items-list",
        );
        queries.push({ term: "/items/search?search=&offset=0&limit=25", ...measuredList });
        const seeded = sql(ctx.mysqlContainer, "SELECT COUNT(*) FROM ospos_items WHERE item_number LIKE 'PF%'");
        return {
            status: seed.ok && seeded.rows?.[0] === "5000" ? "pass" : "fail",
            expected: "5,000 seeded items; three itemSearch terms and item list measured",
            actual: { columns: schema.rows, seed: seed.output, verifiedCount: seeded.rows, measurements: queries },
            note: `Catalog seed ${seed.ok ? "succeeded" : "failed"}; ` + `SQL verified ${seeded.rows?.[0] || 0} rows.`,
        };
    }
    await ctx.login("qacashier", "QACashier2026");
    await ui.goto(`${base}/sales`);
    if (number === 5) {
        const milk = (await ctx.sqlItems()).find((row) => row.name === "Milk");
        if (!milk)
            return {
                status: "blocked",
                blockedBy: "ITEM-01",
                expected: "Milk barcode fixture",
                actual: "Milk fixture is absent from ospos_items",
                note: "Blocked because ITEM-01 did not create Milk.",
            };
        await ui.click(page.locator("#show_suspended_sales_button"));
        await page.locator("#suspended_sales_table").waitFor({ state: "visible", timeout: 10000 });
        const baseline = await page.locator("#suspended_sales_table tbody tr").count();
        const baselineClose = page.locator('.bootstrap-dialog-footer-buttons button[id="close"]').last();
        if (await baselineClose.count()) await ui.click(baselineClose);
        const counts = [];
        let created = 0;
        for (const target of [1, 10, 50]) {
            for (let i = created; i < target; i++) {
                await ui.goto(`${base}/sales`);
                await ui.fill(page.locator("#item"), milk.barcode);
                await ui.press(page.locator("#item"), "Enter");
                await page.waitForTimeout(100);
                await ui.click(page.locator("#suspend_sale_button"));
                await page.waitForTimeout(120);
                created++;
            }
            await ui.goto(`${base}/sales`);
            await ui.click(page.locator("#show_suspended_sales_button"));
            await page.locator("#suspended_sales_table").waitFor({ state: "visible", timeout: 10000 });
            const rows = await page.locator("#suspended_sales_table tbody tr").count();
            counts.push({ expectedNewSales: target, baselineRows: baseline, rows, verifiedNewSales: rows - baseline });
            const close = page.locator('.bootstrap-dialog-footer-buttons button[id="close"]').last();
            if (await close.count()) await ui.click(close);
        }
        return {
            status: counts.every((row) => row.verifiedNewSales === row.expectedNewSales) ? "pass" : "finding",
            expected: "Suspended modal measured with 1, 10, 50 new held sales",
            actual: counts,
            note: "Counted existing held sales before creating new ones through live register actions.",
        };
    }
    const ids = await ctx.sqlItems();
    const barcode = ids.find((row) => row.name === "Milk")?.barcode || "123456789012";
    if (number === 4) {
        const start = Date.now();
        const records = [];
        for (let i = 0; i < 5; i++) {
            await ui.goto(`${base}/items`);
            await ui.click(page.locator('[data-href="items/view"]').first());
            await page.locator("#item_form").waitFor({ state: "visible" });
            const form = page.locator("#item_form");
            await form.locator("#name").fill(`PERF modal ${i}`);
            await form.locator("#category").fill("Grocery");
            await form.locator("#cost_price").fill("1");
            await form.locator("#unit_price").fill("2");
            await form.locator('input[id^="quantity_"]').first().fill("5");
            const save = page.locator('.bootstrap-dialog-footer-buttons button[id="submit"]').last();
            await ui.click(save);
            await page.waitForTimeout(350);
            const stored = sql(
                ctx.mysqlContainer,
                `SELECT item_id, name, unit_price FROM ospos_items
                    WHERE name = 'PERF modal ${i}' ORDER BY item_id DESC LIMIT 1`,
            );
            records.push({ item: i + 1, dialogOpen: await form.isVisible().catch(() => false), sql: stored.rows });
        }
        return {
            status: records.every((row) => row.sql?.length) ? "pass" : "fail",
            expected: "Five item modal saves completed and measured",
            actual: { elapsedMs: Date.now() - start, records },
            note: "Each modal was opened, filled, submitted, and verified through SQL.",
        };
    }
    const itemField = page.locator("#item");
    for (let i = 0; i < 10; i++) {
        await ui.fill(itemField, barcode);
        await ui.press(itemField, "Enter");
        await page.waitForTimeout(100);
    }
    if (number === 3)
        for (let i = 0; i < 3; i++) {
            const qty = page.locator("#register_wrapper input[name=quantity]").nth(i);
            if (await qty.count()) {
                await qty.fill("2");
                await qty.press("Enter");
                await page.waitForTimeout(100);
            }
        }
    const elapsedMs = Date.now() - ctx.measure().started;
    const saleBefore = number === 2 ? Number(sql(ctx.mysqlContainer, "SELECT IFNULL(MAX(sale_id), 0) FROM ospos_sales").rows?.[0] ?? 0) : 0;
    if (number === 2) {
        const amount =
            (
                await page
                    .locator("#sale_total")
                    .innerText()
                    .catch(() => "0")
            )
                .match(/\d[\d,]*\.\d{2}/)?.[0]
                ?.replaceAll(",", "") || "0";
        await page.locator("#amount_tendered").fill(amount);
        await Promise.all([
            page
                .waitForResponse((response) => response.url().includes("/sales/addPaymentAndComplete"), { timeout: 8000 })
                .catch(() => null),
            ui.click(page.locator("#complete_sale_button")),
        ]);
        await page.waitForTimeout(500);
    }
    const errors = ctx.measure().failures;
    const saleSaved = number === 2 ? sql(ctx.mysqlContainer, "SELECT MAX(sale_id) FROM ospos_sales") : null;
    const saleCreated = number !== 2 || !saleSaved?.ok || Number(saleSaved?.rows?.[0] ?? 0) > saleBefore;
    return {
        status: errors.some((error) => error.status >= 500) || !saleCreated ? "fail" : "pass",
        expected:
            number === 2 ? "Ten-item scanner sale paid and completed" : "Ten items added and three quantities changed",
        actual: {
            elapsedMs,
            clicks: ctx.measure().clicks,
            keys: ctx.measure().keys,
            requests: ctx.measure().requests.length,
            cartLines: await page.locator('#register_wrapper form[id^="cart_"]').count(),
            saleSql: saleSaved?.rows,
            errors,
        },
        note: errors.length
            ? `Performance journey recorded ${errors.length} failed HTTP calls.`
            : "Scanner path measured from live register.",
    };
}

/** Time one catalog HTTP request and keep its isolated SQL slow log. */
async function measureSearch(ctx, url, label) {
    if (ctx.mysqlContainer)
        spawnSync("docker", ["exec", ctx.mysqlContainer, "sh", "-c", ": > /var/lib/mysql/slow.log"], {
            encoding: "utf8",
        });
    const start = Date.now();
    const response = await ctx.page.request.get(url);
    const body = await response.body();
    const durationMs = Date.now() - start;
    const route = new URL(url).pathname + new URL(url).search;
    ctx.measure().requests.push({
        method: "GET",
        path: route,
        status: response.status(),
        durationMs,
        bytes: body.length,
        fullNavigation: false,
        resourceType: "xhr",
    });
    let querySql = null;
    if (ctx.mysqlContainer) {
        const log = spawnSync("docker", ["exec", ctx.mysqlContainer, "cat", "/var/lib/mysql/slow.log"], {
            encoding: "utf8",
            maxBuffer: 20 * 1024 * 1024,
        });
        if (log.status === 0) {
            querySql = summarizeSql(log.stdout);
            await fs.writeFile(path.join(ctx.out, "slowlog", `${label}.log`), log.stdout);
        }
    }
    return { elapsedMs: durationMs, status: response.status(), bytes: body.length, sql: querySql };
}
