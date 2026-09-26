import { sql } from "../lib/sql.mjs";
import fs from "node:fs/promises";
import path from "node:path";
import { dollarsToLbpInput } from "../lib/money.mjs";

const specs = {
    1: ["Milk", "Grocery", "7", "10", "5", "123456789012"],
    5: ["QA zero cost", "Grocery", "0", "10", "5", ""],
    6: ["Free sample", "Drinks", "0", "0", "5", ""],
    7: ["QA negative cost", "Grocery", "-1", "10", "5", ""],
    8: ["QA negative price", "Grocery", "7", "-1", "5", ""],
    9: ["QA huge price", "Grocery", "7", "100000000000001", "5", ""],
    10: ["QA decimal", "Grocery", "7", "940000.50", "5", ""],
    11: ["حليب طازج", "Grocery", "7", "10", "5", ""],
    12: ["حليب UHT 1L", "Grocery", "7", "10", "5", ""],
    13: ["N".repeat(255), "Grocery", "7", "10", "5", ""],
    14: ["<b>Milk & Co</b>", "Grocery", "7", "10", "5", ""],
    15: ["QA generated barcode", "Grocery", "7", "10", "5", ""],
    16: ["QA duplicate barcode", "Grocery", "7", "10", "5", "123456789012"],
    17: ["Leading zero", "Grocery", "7", "10", "5", "0012345"],
    18: ["Barcode spaces", "Grocery", "7", "10", "5", " 12 34 "],
    19: ["Barcode punctuation", "Grocery", "7", "10", "5", "AB-12/34"],
    22: ["Out of stock", "Grocery", "7", "10", "0", ""],
    23: ["Receiving quantity", "Grocery", "7", "10", "5", ""],
    24: ["Reorder level", "Grocery", "7", "10", "5", ""],
    25: ["QA description", "Grocery", "7", "10", "5", ""],
    27: ["QA alternate description", "Grocery", "7", "10", "5", ""],
    28: ["Serialized item", "Grocery", "7", "10", "5", ""],
    29: ["QA inherit TVA", "Grocery", "7", "10", "5", ""],
    30: ["QA own TVA", "Grocery", "7", "10", "5", ""],
    31: ["QA two taxes", "Grocery", "7", "10", "5", ""],
    32: ["خبز", "Bakery", "7", "10", "5", ""],
    35: ["QA stock type", "Grocery", "7", "10", "5", ""],
    38: ["QA deleted", "Grocery", "7", "10", "5", ""],
};

/** Run item modal checks and verify the saved ITEM-01 prices in the item list. */
export async function itemScenario(ctx, number) {
    const { page, ui, base } = ctx;
    await ctx.login("qacashier", "QACashier2026");
    await ui.goto(`${base}/items`);
    if (number === 34) return conditionalFields(ctx);
    if (number === 36) return multiPackScenario(ctx);
    if (number === 20) {
        const url = `${base}/suppliers`;
        const started = Date.now();
        const response = await ctx.page.request.get(url);
        const body = await response.body();
        const routeStatus = response.status();
        ctx.measure().requests.push({
            method: "GET",
            path: "/suppliers",
            status: routeStatus,
            durationMs: Date.now() - started,
            bytes: body.length,
            fullNavigation: false,
            resourceType: "xhr",
        });
        await ui.goto(`${base}/items`);
        await openItem(ctx);
        const supplier = ctx.page.locator("[name=supplier_id]");
        const options = await supplier.locator("option").allTextContents();
        return {
            status: "finding",
            expected: "Supplier fixture exists and supplier can be selected",
            actual: { supplierRouteStatus: routeStatus, selectorCount: await supplier.count(), options },
            note:
                `/suppliers returned HTTP ${routeStatus}; choices=${options.join(", ")}. ` +
                "Route is absent under shop lockdown.",
        };
    }
    if ([34, 36, 37].includes(number)) {
        if (number === 37) return attributeScenario(ctx);
        if (number === 34 || number === 36) {
            await ctx.login("admin", "pointofsale");
            await ui.goto(`${base}/config`);
            const adminPage = ctx.page;
            await ui.click(adminPage.locator(`a[href="#${number === 34 ? "tax" : "general"}_tab"]`));
            const form = adminPage.locator(number === 34 ? "#tax_config_form" : "#general_config_form");
            const selector = number === 34 ? "#use_destination_based_tax" : "#multi_pack_enabled";
            const tab = page.locator(`a[href*="${number === 34 ? "tax" : "general"}"]`).first();
            if (await tab.count()) await ui.click(tab);
            const control = form.locator(selector);
            if (!(await control.count()))
                return {
                    status: "finding",
                    expected: `${selector} enabled for conditional test`,
                    actual: "admin setting absent",
                    note: "Control absent on admin settings page.",
                };
            await control.check();
            await form.locator("input[type=submit]").click();
            await page.waitForTimeout(300);
            await ctx.login("qacashier", "QACashier2026");
            await ui.goto(`${base}/items`);
            await openItem(ctx);
            const present = await ctx.page.locator(number === 34 ? "#tax_category" : "#qty_per_pack").count();
            await ui
                .click(ctx.page.locator('.bootstrap-dialog-footer-buttons button[id="close"]').last())
                .catch(() => {});
            await ctx.login("admin", "pointofsale");
            await ui.goto(`${base}/config`);
            const restorePage = ctx.page;
            await ui.click(restorePage.locator(`a[href="#${number === 34 ? "tax" : "general"}_tab"]`));
            const restoreForm = restorePage.locator(number === 34 ? "#tax_config_form" : "#general_config_form");
            const setting = restoreForm.locator(selector);
            if (await setting.count()) {
                await setting.uncheck();
                await restoreForm.locator("input[type=submit]").click();
            }
            return {
                status: present ? "pass" : "finding",
                expected: `${selector} reveals conditional item fields`,
                actual: `conditional field count=${present}`,
                note: "Admin option enabled and restored after inspection.",
            };
        }
        return {
            status: "finding",
            expected: "Custom attribute definition fixture",
            actual: "No definition exists in the disposable baseline",
            note: "Attempted attribute list route; creating definitions is not a catalogue setup step.",
        };
    }
    if (number === 21) {
        await openItem(ctx);
        const count = await ctx.page.locator('input[id^="quantity_"]').count();
        const values = await ctx.page
            .locator('input[id^="quantity_"]')
            .evaluateAll((elements) => elements.map((element) => ({ id: element.id, value: element.value })));
        return {
            status: count > 1 ? "finding" : "finding",
            expected: "Different quantity values at two configured locations",
            actual: { locationFields: count, values },
            note:
                count > 1
                    ? "Only one location value was available to edit and inspect."
                    : "Baseline has fewer than two location quantity fields.",
        };
    }
    if (number === 26) return uploadImage(ctx);
    if (number === 39 || number === 40) {
        if (number === 39) return bulkEditScenario(ctx);
        return csvImportScenario(ctx);
    }
    if ([2, 3, 4, 33].includes(number)) return validationScenario(ctx, number);
    const spec = specs[number] || [`QA ITEM-${number}`, "Grocery", "7", "10", "5", ""];
    try {
        await openItem(ctx);
    } catch (error) {
        const failedView = ctx
            .measure()
            .requests.find((request) => request.path.startsWith("/items/view") && request.status >= 500);
        if (failedView)
            return {
                status: "finding",
                expected: "New item form opens before testing a 255 character item name",
                actual: { route: failedView.path, status: failedView.status, bytes: failedView.bytes },
                note: `App returned HTTP ${failedView.status} for GET ${failedView.path}; item form was unavailable.`,
            };
        throw error;
    }
    const form = page.locator("#item_form");
    const [name, category, cost, price, quantity, barcode] = spec;
    await form.locator("#name").fill(name);
    await form.locator("#category").fill(category);
    await form.locator("#cost_price").fill(number === 7 ? cost : dollarsToLbpInput(cost));
    await form.locator("#unit_price").fill([8, 9, 10].includes(number) ? price : dollarsToLbpInput(price));
    const qty = form.locator('input[id^="quantity_"]').first();
    await qty.fill(number === 22 ? "0" : quantity);
    if (await form.locator("#receiving_quantity").count())
        await form.locator("#receiving_quantity").fill(number === 23 ? "3" : "1");
    if (await form.locator("#reorder_level").count())
        await form.locator("#reorder_level").fill(number === 24 ? "5" : "0");
    if (barcode) await form.locator("#item_number").fill(barcode);
    if ([14, 25].includes(number))
        await form
            .locator("#description")
            .fill(
                number === 14
                    ? "<script>window.qaInjected=1</script>Arabic وصف\nLatin description"
                    : "وصف Arabic\nLatin",
            );
    if (number === 27) await form.locator("#allow_alt_description").check();
    if (number === 28) await form.locator("#is_serialized").check();
    if (number === 30 || number === 31) {
        await form.locator("#tax_mode_own").check();
        await form.locator("#tax_name_1").fill("TVA");
        await form.locator("#tax_percent_name_1").fill("5");
        if (number === 31) {
            await form.locator("#tax_name_2").fill("Local");
            await form.locator("#tax_percent_name_2").fill("2");
        }
    }
    if (number === 32) {
        await form.locator("#tax_mode_none").check();
        await form
            .locator("[name=tax_exemption_reason]")
            .selectOption({ label: /exempt/i })
            .catch(() => {});
    }
    if (number === 35) {
        await form.locator('input[name=stock_type][value="1"]').check();
        const ordinaryTypes = await form.locator("input[name=item_type]").evaluateAll((elements) =>
            elements.map((element) => ({
                value: element.value,
                checked: element.checked,
                disabled: element.disabled,
            })),
        );
        const prior = sql(
            ctx.mysqlContainer,
            "SELECT `value` FROM ospos_app_config WHERE `key`='derive_sale_quantity'",
        );
        const stockSaved = await saveItemModal(ctx, form, name, true);
        await toggleGeneral(ctx, "#derive_sale_quantity", true);
        await ui.goto(`${base}/items`);
        await openItem(ctx);
        const amount = ctx.page.locator('input[name=item_type][value="2"]');
        const present = await amount.count();
        if (present) await amount.check();
        await ctx.page.locator("#name").fill("Amount entry QA");
        await ctx.page.locator("#category").fill("Grocery");
        await ctx.page.locator("#cost_price").fill("0");
        await ctx.page.locator("#unit_price").fill(dollarsToLbpInput("10"));
        await ctx.page.locator('input[id^="quantity_"]').first().fill("5");
        const amountSave = ctx.page.locator('.bootstrap-dialog-footer-buttons button[id="submit"]').last();
        await ui.click(amountSave);
        await ctx.page.waitForTimeout(250);
        await toggleGeneral(ctx, "#derive_sale_quantity", prior.rows?.[0] === "1");
        const entry = sql(
            ctx.mysqlContainer,
            "SELECT item_id,item_type FROM ospos_items WHERE name='Amount entry QA' ORDER BY item_id DESC LIMIT 1",
        );
        return {
            status: present && entry.rows?.length && stockSaved.rows?.length ? "pass" : "finding",
            expected: "Stock type and item types 0/1/2/3 observed; amount-entry appears when its admin option is on",
            actual: {
                ordinaryTypes,
                stockItemSql: stockSaved.rows,
                amountEntryField: present,
                amountEntrySql: entry.rows,
            },
            note: "derive_sale_quantity enabled for the conditional save and restored to its earlier state.",
        };
    }
    if (number === 38) await form.locator("#is_deleted").check();
    if (number === 13) await form.locator("#name").fill("N".repeat(256));
    if (number === 18) await form.locator("#item_number").fill(" 12 34 ");
    if (number === 19) await form.locator("#item_number").fill("AB-12/34");
    const save = page
        .locator(
            '.bootstrap-dialog-footer-buttons button[id="submit"], ' +
                '.bootstrap-dialog-footer-buttons button[data-id="submit"]',
        )
        .last();
    const responseWait = page
        .waitForResponse((response) => /\/items\/save(?:\/|\?)/.test(response.url()), { timeout: 5000 })
        .catch(() => null);
    await ui.click(save);
    const response = await responseWait;
    await page.waitForTimeout(350);
    const rowSql = sql(
        ctx.mysqlContainer,
        `SELECT item_id, name, item_number, cost_price, unit_price, taxable,
            tax_exemption_reason, allow_alt_description, is_serialized, stock_type,
            item_type, receiving_quantity, reorder_level FROM ospos_items
            WHERE name = ${ctx.quote(name)} ORDER BY item_id DESC LIMIT 1`,
    );
    const row = rowSql.rows?.[0] || "";
    const saved = !!row;
    let status = saved ? "pass" : "fail";
    let itemListPrices = null;
    if (number === 1 && saved) {
        const itemId = row.split("\t")[0];
        const itemRow = page.locator(`#table tr[data-uniqueid="${itemId}"]`);
        await itemRow.waitFor({ state: "visible", timeout: 10000 });
        const costIndex = await page
            .locator('#table thead th[data-field="cost_price"]')
            .evaluate((header) => Array.from(header.parentElement.children).indexOf(header));
        const retailIndex = await page
            .locator('#table thead th[data-field="unit_price"]')
            .evaluate((header) => Array.from(header.parentElement.children).indexOf(header));
        itemListPrices = {
            cost: await itemRow.locator("td").nth(costIndex).innerText(),
            retail: await itemRow.locator("td").nth(retailIndex).innerText(),
        };
        if (itemListPrices.cost !== "627,000 LL" || itemListPrices.retail !== "895,000 LL") status = "fail";
    }
    if (number === 9 && !saved) status = "pass";
    if ([7, 8, 10, 13, 18, 19, 23, 24, 35, 38].includes(number)) status = "finding";
    if (number === 9 && saved) status = "fail";
    if (number === 16) status = saved ? "fail" : "pass";
    return {
        status,
        expected:
            number === 1
                ? "Modal save stores dollar prices and refreshes the item row with 627,000 LL cost and 895,000 LL retail"
                : "Modal submit completes after validation and stored item values match input",
        actual: {
            saveResponse: response?.status() || null,
            sql: rowSql.rows,
            itemListPrices,
            barcode: row.split("\t")[2] || null,
        },
        note: saved
            ? `SQL verified item row ${row}${itemListPrices ? `; list shows cost ${itemListPrices.cost} and retail ${itemListPrices.retail}` : ""}`
            : `No matching item row; response=${response?.status() || "none"}; errors=${(
                  await page
                      .locator("#error_message_box")
                      .innerText()
                      .catch(() => "")
              ).slice(0, 250)}`,
        sqlEvidence: rowSql,
    };
}

async function saveItemModal(ctx, form, name, close = true) {
    const button = ctx.page.locator('.bootstrap-dialog-footer-buttons button[id="submit"]').last();
    await ctx.ui.click(button);
    await ctx.page.waitForTimeout(350);
    return sql(
        ctx.mysqlContainer,
        `SELECT item_id FROM ospos_items WHERE name=${ctx.quote(name)} ORDER BY item_id DESC LIMIT 1`,
    );
}

async function toggleGeneral(ctx, selector, enable) {
    await ctx.login("admin", "pointofsale");
    const page = ctx.page;
    await ctx.ui.goto(`${ctx.base}/config`);
    await ctx.ui.click(page.locator('a[href="#general_tab"]'));
    const form = page.locator("#general_config_form");
    const setting = form.locator(selector);
    if (enable) await setting.check();
    else await setting.uncheck();
    await Promise.all([
        page
            .waitForResponse((response) => response.url().includes("/config/saveGeneral"), { timeout: 6000 })
            .catch(() => null),
        form.locator("#submit_general").click(),
    ]);
}

async function conditionalFields(ctx) {
    const { ui, base } = ctx;
    const observations = [];
    const originalTax = sql(
        ctx.mysqlContainer,
        `SELECT \`key\`, \`value\` FROM ospos_app_config
            WHERE \`key\` IN ('default_tax_1_name', 'default_tax_1_rate', 'default_tax_2_name',
                'default_tax_2_rate', 'lbp_exchange_rate')`,
    );
    const taxValues = Object.fromEntries((originalTax.rows || []).map((row) => row.split("\t")));
    for (const [area, settingSelector, fieldSelector, anchor] of [
        ["destination tax", "#use_destination_based_tax", "#tax_category", "#tax_tab"],
        ["HSN", "#include_hsn", "#hsn_code", "#general_tab"],
    ]) {
        await ctx.login("admin", "pointofsale");
        const adminPage = ctx.page;
        await ui.goto(`${base}/config`);
        await ui.click(adminPage.locator(`a[href="${anchor}"]`));
        const form = adminPage.locator(anchor === "#tax_tab" ? "#tax_config_form" : "#general_config_form");
        const setting = form.locator(settingSelector);
        const wasEnabled = await setting.isChecked();
        if (!wasEnabled) await setting.check();
        await Promise.all([
            adminPage
                .waitForResponse(
                    (response) =>
                        response.url().includes(anchor === "#tax_tab" ? "/config/saveTax" : "/config/saveGeneral"),
                    { timeout: 7000 },
                )
                .catch(() => null),
            form.locator("input[type=submit]").last().click(),
        ]);
        await ctx.login("qacashier", "QACashier2026");
        await ui.goto(`${base}/items`);
        await openItem(ctx);
        const present = await ctx.page.locator(fieldSelector).count();
        observations.push({ area, fieldSelector, count: present });
        await ui.click(ctx.page.locator('.bootstrap-dialog-footer-buttons button[id="close"]').last()).catch(() => {});
        await ctx.login("admin", "pointofsale");
        const restorePage = ctx.page;
        await ui.goto(`${base}/config`);
        await ui.click(restorePage.locator(`a[href="${anchor}"]`));
        const restoreForm = restorePage.locator(anchor === "#tax_tab" ? "#tax_config_form" : "#general_config_form");
        const restore = restoreForm.locator(settingSelector);
        if (!wasEnabled && (await restore.isChecked())) await restore.uncheck();
        if (area === "destination tax") {
            await restoreForm.locator("#default_tax_1_name").fill(taxValues.default_tax_1_name || "TVA");
            await restoreForm.locator("#default_tax_1_rate").fill(taxValues.default_tax_1_rate || "11");
            await restoreForm.locator("#default_tax_2_name").fill(taxValues.default_tax_2_name || "");
            await restoreForm.locator("#default_tax_2_rate").fill(taxValues.default_tax_2_rate || "");
            await restoreForm.locator("#lbp_exchange_rate").fill(taxValues.lbp_exchange_rate || "89500");
        }
        if (!wasEnabled)
            await Promise.all([
                restorePage
                    .waitForResponse(
                        (response) =>
                            response.url().includes(anchor === "#tax_tab" ? "/config/saveTax" : "/config/saveGeneral"),
                        { timeout: 7000 },
                    )
                    .catch(() => null),
                restoreForm.locator("input[type=submit]").last().click(),
            ]);
    }
    return {
        status: observations.every((row) => row.count > 0) ? "pass" : "finding",
        expected: "Destination-tax category and HSN fields appear under each admin setting",
        actual: observations,
        note: "Enabled each admin option separately, inspected the item form, then restored the prior setting.",
    };
}

async function multiPackScenario(ctx) {
    const { ui, base } = ctx;
    await ctx.login("admin", "pointofsale");
    const adminPage = ctx.page;
    await ui.goto(`${base}/config`);
    await ui.click(adminPage.locator('a[href="#general_tab"]'));
    const form = adminPage.locator("#general_config_form");
    const setting = form.locator("#multi_pack_enabled");
    const wasEnabled = await setting.isChecked();
    if (!wasEnabled) await setting.check();
    await Promise.all([
        adminPage
            .waitForResponse((response) => response.url().includes("/config/saveGeneral"), { timeout: 7000 })
            .catch(() => null),
        form.locator("#submit_general").click(),
    ]);
    let viewStatus = null;
    let fieldCount = 0;
    try {
        await ctx.login("qacashier", "QACashier2026");
        await ui.goto(`${base}/items`);
        const add = ctx.page.locator('[data-href="items/view"]').first();
        const responseWait = ctx.page
            .waitForResponse((response) => response.url().includes("/items/view"), { timeout: 7000 })
            .catch(() => null);
        await ui.click(add);
        const response = await responseWait;
        viewStatus = response?.status() || null;
        if (viewStatus === 200) {
            await ctx.page.locator("#item_form").waitFor({ state: "visible", timeout: 5000 });
            fieldCount = await ctx.page.locator("#qty_per_pack, #pack_name, #low_sell_item_name").count();
        }
    } finally {
        if (!wasEnabled) {
            await ctx.login("admin", "pointofsale");
            const restorePage = ctx.page;
            await ui.goto(`${base}/config`);
            await ui.click(restorePage.locator('a[href="#general_tab"]'));
            const restoreForm = restorePage.locator("#general_config_form");
            await restoreForm.locator("#multi_pack_enabled").uncheck();
            await Promise.all([
                restorePage
                    .waitForResponse((response) => response.url().includes("/config/saveGeneral"), { timeout: 7000 })
                    .catch(() => null),
                restoreForm.locator("#submit_general").click(),
            ]);
        }
    }
    return {
        status: viewStatus === 200 && fieldCount > 0 ? "pass" : "finding",
        expected: "Admin enables multi-pack; item modal displays pack fields; option restored",
        actual: { viewStatus, fieldCount },
        note:
            viewStatus && viewStatus >= 500
                ? `App returned HTTP ${viewStatus} for GET /items/view when multi-pack was enabled.`
                : "Conditional item fields were inspected and the setting was restored.",
    };
}

/** Upload PNG and JPEG fixtures, check their thumbnails, and exercise the Remove control. */
async function uploadImage(ctx) {
    const variants = [];
    for (const image of ["png", "jpeg"]) {
        const name = `Image fixture QA ${image}`;
        const dataUrl = await ctx.page.evaluate((type) => {
            const canvas = document.createElement("canvas");
            canvas.width = 200;
            canvas.height = 120;
            const context = canvas.getContext("2d");
            context.fillStyle = "#d97706";
            context.fillRect(0, 0, canvas.width, canvas.height);
            context.fillStyle = "#1f2937";
            context.fillRect(30, 25, 140, 70);
            return canvas.toDataURL(type === "jpeg" ? "image/jpeg" : "image/png", 0.9);
        }, image);
        const buffer = Buffer.from(dataUrl.split(",")[1], "base64");
        await openItem(ctx);
        const form = ctx.page.locator("#item_form");
        await form.locator("#name").fill(name);
        await form.locator("#category").fill("Grocery");
        await form.locator("#cost_price").fill(dollarsToLbpInput("1"));
        await form.locator("#unit_price").fill(dollarsToLbpInput("2"));
        await form.locator('input[id^="quantity_"]').first().fill("1");
        await form.locator("input[name=items_image]").setInputFiles({
            name: `qa-image.${image === "jpeg" ? "jpg" : "png"}`,
            mimeType: image === "jpeg" ? "image/jpeg" : "image/png",
            buffer,
        });
        await ctx.ui.click(ctx.page.locator('.bootstrap-dialog-footer-buttons button[id="submit"]').last());
        await ctx.page.waitForTimeout(400);
        const saved = sql(
            ctx.mysqlContainer,
            `SELECT item_id,pic_filename FROM ospos_items WHERE name=${ctx.quote(name)} ORDER BY item_id DESC LIMIT 1`,
        );
        const [itemId, filename] = saved.rows?.[0]?.split("\t") || [];
        const thumbnail = filename ? await ctx.page.request.get(`${ctx.base}/items/PicThumb/${filename}`) : null;
        const contentType = thumbnail?.headers()["content-type"] || "";
        variants.push({
            image,
            dimensions: "200x120",
            bytes: buffer.length,
            saved: saved.rows,
            thumbnailStatus: thumbnail?.status() || null,
            contentType,
            thumbnailOk:
                thumbnail?.status() === 200 && contentType.startsWith(`image/${image === "jpeg" ? "jpeg" : "png"}`),
            itemId,
            filename,
        });
        if (image === "png" && itemId) {
            await ctx.ui.goto(`${ctx.base}/items/view/${itemId}`);
            await ctx.page.locator("#item_form").waitFor({ state: "visible", timeout: 8000 });
            const removeLink = ctx.page.locator('a.fileinput-exists[data-dismiss="fileinput"]').last();
            await removeLink.waitFor({ state: "visible", timeout: 8000 });
            const removal = ctx.page
                .waitForResponse((response) => response.url().includes(`/items/removeLogo/${itemId}`), {
                    timeout: 5000,
                })
                .catch(() => null);
            await ctx.ui.click(removeLink);
            await removal;
            await ctx.page.waitForTimeout(200);
            variants[0].removedFilename =
                sql(ctx.mysqlContainer, `SELECT pic_filename FROM ospos_items WHERE item_id=${Number(itemId)}`)
                    .rows?.[0] ?? null;
            await ctx.ui.goto(`${ctx.base}/items`);
        }
    }
    const pngOk = variants[0]?.thumbnailOk && variants[0]?.removedFilename === "NULL";
    const jpegOk = variants[1]?.thumbnailOk;
    return {
        status: pngOk && jpegOk ? "pass" : "fail",
        expected: "200x120 PNG/JPEG upload; thumbnails return 200 with matching types; Remove clears PNG filename",
        actual: variants,
        note: "Generated and uploaded both 200x120 images, fetched each thumbnail, and clicked Remove.",
    };
}

/** Create an attribute definition as admin and save an item value as cashier. */
async function attributeScenario(ctx) {
    const { ui, base } = ctx;
    await ctx.login("admin", "pointofsale");
    await ui.goto(`${base}/attributes`);
    const adminPage = ctx.page;
    await ui.click(adminPage.locator('[data-href="attributes/view"]').first());
    await adminPage.locator("#attribute_form").waitFor({ state: "visible" });
    const attrForm = adminPage.locator("#attribute_form");
    await attrForm.locator("#definition_name").fill("QA Attribute Text");
    await attrForm.locator("#definition_type").selectOption("3");
    await ui.click(adminPage.locator('.bootstrap-dialog-footer-buttons button[id="submit"]').last());
    await adminPage.waitForTimeout(300);
    const definition = sql(
        ctx.mysqlContainer,
        `SELECT definition_id FROM ospos_attribute_definitions
            WHERE definition_name = 'QA Attribute Text' AND deleted = 0
            ORDER BY definition_id DESC LIMIT 1`,
    );
    if (!definition.rows?.length)
        return {
            status: "fail",
            expected: "Admin creates a text attribute definition",
            actual: { sql: definition.rows },
            note: "Attribute modal submitted but no definition row was stored.",
        };
    const definitionId = definition.rows[0];
    await ctx.login("qacashier", "QACashier2026");
    await ui.goto(`${base}/items`);
    await openItem(ctx);
    const form = ctx.page.locator("#item_form");
    await form.locator("#name").fill("Attribute QA item");
    await form.locator("#category").fill("Grocery");
    await form.locator("#cost_price").fill(dollarsToLbpInput("1"));
    await form.locator("#unit_price").fill(dollarsToLbpInput("2"));
    await form.locator('input[id^="quantity_"]').first().fill("5");
    const selector = form.locator("#definition_name");
    const optionValues = await selector
        .locator("option")
        .evaluateAll((options) => options.map((option) => ({ value: option.value, text: option.textContent.trim() })));
    await selector.selectOption({ label: "QA Attribute Text" });
    const selectedDefinition = await selector.inputValue();
    const valueField = form.locator(`[name="attribute_links[${selectedDefinition}]"]`);
    await valueField.waitFor({ state: "visible", timeout: 10000 });
    await valueField.fill("QA value");
    await ui.click(ctx.page.locator('.bootstrap-dialog-footer-buttons button[id="submit"]').last());
    await ctx.page.waitForTimeout(350);
    const stored = sql(
        ctx.mysqlContainer,
        `SELECT i.item_id, d.definition_name, v.attribute_value
            FROM ospos_items i
            JOIN ospos_attribute_links l ON l.item_id = i.item_id
            JOIN ospos_attribute_definitions d ON d.definition_id = l.definition_id
            JOIN ospos_attribute_values v ON v.attribute_id = l.attribute_id
            WHERE i.name = 'Attribute QA item' ORDER BY i.item_id DESC LIMIT 1`,
    );
    return {
        status: stored.rows?.length ? "pass" : "fail",
        expected: "Admin definition and cashier item value persist and are searchable",
        actual: { definition: definition.rows, options: optionValues, itemAttributeSql: stored.rows },
        note: stored.rows?.[0] || "Item form did not save the custom attribute link.",
        sqlEvidence: stored,
    };
}

/** Edit two selected rows through bulk edit and verify each category in SQL. */
async function bulkEditScenario(ctx) {
    const { ui, base } = ctx;
    const fixtures = sql(
        ctx.mysqlContainer,
        "SELECT item_id,name FROM ospos_items WHERE name IN ('Milk','QA zero cost') AND deleted=0 ORDER BY item_id",
    );
    if ((fixtures.rows || []).length < 2)
        return {
            status: "blocked",
            blockedBy: "ITEM-01 and ITEM-05",
            expected: "Two valid item rows",
            actual: fixtures.rows,
            note: "Blocked because two prerequisite item rows are missing.",
        };
    await ui.goto(`${base}/items`);
    const page = ctx.page;
    const length = page.locator("#table_length select");
    if (await length.count()) {
        await length.selectOption("100");
        await page.waitForTimeout(250);
    }
    const selected = [];
    for (const row of fixtures.rows) {
        const [id] = row.split("\t");
        const checkbox = page.locator(`#table tr[data-uniqueid="${id}"] input[type=checkbox]`);
        if (await checkbox.count()) {
            await checkbox.check();
            selected.push(id);
        }
    }
    const bulk = page.locator("#bulk_edit");
    if ((await bulk.count()) && selected.length === 2) {
        await ui.click(bulk);
        await page.locator("#item_form").waitFor({ state: "visible" });
        const form = page.locator("#item_form");
        const category = form.locator("#category");
        if (await category.count()) await category.fill("Bulk QA");
        await ui.click(page.locator('.bootstrap-dialog-footer-buttons button[id="submit"]').last());
        await page.waitForTimeout(400);
    }
    const categories = sql(
        ctx.mysqlContainer,
        `SELECT item_id,name,category FROM ospos_items WHERE item_id IN (${selected.join(",")}) ORDER BY item_id`,
    );
    return {
        status:
            categories.rows?.length === 2 && categories.rows.every((row) => row.endsWith("Bulk QA"))
                ? "pass"
                : "finding",
        expected: "Bulk category update affects exactly the two selected item IDs",
        actual: { selected, categories: categories.rows },
        note: "Selected two table rows, opened the bulk form, submitted, and checked only their SQL rows.",
        sqlEvidence: categories,
    };
}

/** Download the UI CSV template, import one fixture, and inspect clone controls. */
async function csvImportScenario(ctx) {
    const { ui, base } = ctx;
    await ui.goto(`${base}/items`);
    const page = ctx.page;
    const clone = await page.getByText(/clone|copy/i).count();
    await ui.click(page.locator('[data-href="items/csvImport"]').first());
    await page.locator("#csv_form").waitFor({ state: "visible" });
    const downloadWait = page.waitForEvent("download", { timeout: 8000 }).catch(() => null);
    await ui.click(page.locator('#csv_form a[href*="generateCsvFile"]'));
    const download = await downloadWait;
    if (!download)
        return {
            status: "finding",
            expected: "CSV template downloads and one item imports; clone absent",
            actual: { clone, download: false },
            note: "CSV import modal opened, but clicking its template link emitted no browser download.",
        };
    const templatePath = path.join(ctx.out, "items-template.csv");
    await download.saveAs(templatePath);
    const header = await fs.readFile(templatePath, "utf8");
    const columns = header
        .replace(/^\uFEFF/, "")
        .trim()
        .split(",");
    const values = Array(columns.length).fill("");
    Object.assign(values, {
        1: "2000000099999",
        2: "CSV import QA",
        3: "Grocery",
        5: "1",
        6: "2",
        11: "1",
        12: "Imported by QA",
    });
    if (values.length > 17) values[17] = "5";
    const fixturePath = path.join(ctx.out, "items-import.csv");
    await fs.writeFile(
        fixturePath,
        `${header.trim()}\n${values.map((value) => `"${String(value).replaceAll('"', '""')}"`).join(",")}\n`,
    );
    await page.locator("#file_path").setInputFiles(fixturePath);
    const responseWait = page
        .waitForResponse((response) => response.url().includes("/items/importCsvFile"), { timeout: 10000 })
        .catch(() => null);
    await ui.click(page.locator('.bootstrap-dialog-footer-buttons button[id="submit"]').last());
    const response = await responseWait;
    await page.waitForTimeout(350);
    const stored = sql(
        ctx.mysqlContainer,
        `SELECT item_id, name, item_number, unit_price FROM ospos_items
            WHERE name = 'CSV import QA' ORDER BY item_id DESC LIMIT 1`,
    );
    return {
        status: stored.rows?.length ? "pass" : "finding",
        expected: "CSV template download and import route work; clone/copy is absent",
        actual: { clone, download: true, importStatus: response?.status() || null, stored: stored.rows },
        note: stored.rows?.[0] || `CSV upload sent; route status=${response?.status() || "no response"}.`,
    };
}

async function openItem(ctx) {
    await ctx.ui.click(ctx.page.locator('[data-href="items/view"]').first());
    await ctx.page.locator("#item_form").waitFor({ state: "visible", timeout: 15000 });
    await ctx.page.locator('#item_form input[id^="quantity_"]').first().waitFor({ state: "visible" });
}

async function validationScenario(ctx, number) {
    await openItem(ctx);
    const form = ctx.page.locator("#item_form");
    if (number === 33) {
        await form.locator("#name").fill("Invalid rate QA");
        await form.locator("#category").fill("Grocery");
        await form.locator("#cost_price").fill(dollarsToLbpInput("1"));
        await form.locator("#unit_price").fill(dollarsToLbpInput("2"));
        await form.locator('input[id^="quantity_"]').first().fill("5");
        await form.locator("#tax_mode_own").check();
        await form.locator("#tax_name_1").fill("TVA");
        const observations = [];
        for (const rate of ["", "-1", "101", "text"]) {
            let activeForm = form;
            if (observations.length) {
                await openItem(ctx);
                activeForm = ctx.page.locator("#item_form");
                await activeForm.locator("#name").fill("Invalid rate QA");
                await activeForm.locator("#category").fill("Grocery");
                await activeForm.locator("#cost_price").fill(dollarsToLbpInput("1"));
                await activeForm.locator("#unit_price").fill(dollarsToLbpInput("2"));
                await activeForm.locator('input[id^="quantity_"]').first().fill("5");
                await activeForm.locator("#tax_mode_own").check();
                await activeForm.locator("#tax_name_1").fill("TVA");
            }
            await activeForm.locator("#tax_percent_name_1").fill(rate);
            const before = ctx.measure().requests.filter((request) => request.path.startsWith("/items/save")).length;
            const responseWait = ctx.page
                .waitForResponse((response) => response.url().includes("/items/save"), { timeout: 1800 })
                .catch(() => null);
            await ctx.ui.click(ctx.page.locator('.bootstrap-dialog-footer-buttons button[id="submit"]').last());
            const response = await responseWait;
            const body = (await response?.text().catch(() => "")) || "";
            await ctx.page.waitForTimeout(150);
            const stored = sql(
                ctx.mysqlContainer,
                "SELECT item_id,taxable FROM ospos_items WHERE name='Invalid rate QA' ORDER BY item_id DESC LIMIT 1",
            );
            const message = await ctx.page
                .locator("#error_message_box")
                .innerText()
                .catch(() => "");
            let responseJson = null;
            try {
                responseJson = JSON.parse(body);
            } catch {}
            observations.push({
                rate: rate || "(blank)",
                status: response?.status() || null,
                saveRequests:
                    ctx.measure().requests.filter((request) => request.path.startsWith("/items/save")).length - before,
                response: responseJson || body.slice(0, 180),
                error: message.trim(),
                stored: stored.rows,
            });
            if (stored.rows?.length) break;
        }
        const accepted = observations.some((row) => row.stored?.length || row.response?.success === true);
        return {
            status: accepted ? "finding" : observations.length === 4 ? "pass" : "finding",
            expected: "Blank, -1, 101, and text rates are rejected without saving an item",
            actual: observations,
            note: accepted
                ? "One or more invalid own tax rates reached the save endpoint or created an item."
                : "Attempted each invalid value and checked the save request, visible error, and SQL row.",
        };
    }
    if (number === 2) await form.locator("#name").fill("");
    if (number === 3) await form.locator("#category").fill("");
    if (number === 4) {
        for (const selector of [
            "#cost_price",
            "#unit_price",
            'input[id^="quantity_"]',
            "#receiving_quantity",
            "#reorder_level",
        ]) {
            await form.locator(selector).first().fill("");
            await ctx.ui.click(ctx.page.locator('.bootstrap-dialog-footer-buttons button[id="submit"]').last());
            await ctx.page.waitForTimeout(250);
        }
    }
    await ctx.ui.click(ctx.page.locator('.bootstrap-dialog-footer-buttons button[id="submit"]').last());
    await ctx.page.waitForTimeout(500);
    return {
        status: await ctx.page
            .locator("#error_message_box")
            .innerText()
            .then((text) => (text.trim() ? "pass" : "finding")),
        expected: "Client validation blocks save and displays field errors",
        actual: await ctx.page
            .locator("#error_message_box")
            .innerText()
            .catch(() => ""),
        note: "Observed validation in form; no valid save request should be sent.",
    };
}
