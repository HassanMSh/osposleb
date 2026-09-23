import { sql } from "../lib/sql.mjs";

/** Apply shop locale, TVA, barcode settings, and create the Arabic cashier. */
export async function setup(ctx) {
    const { page, ui, base } = ctx;
    await ctx.login("admin", "pointofsale");
    await ui.goto(`${base}/config`);
    await ui.click(page.locator('a[href="#locale_tab"]'));
    const locale = page.locator("#locale_config_form");
    await locale.waitFor({ state: "visible" });
    await locale.locator("[name=language]").selectOption("ar-LB:arabic");
    await locale.locator("[name=timezone]").selectOption("Asia/Beirut");
    await locale.locator("#currency_symbol").fill("$");
    await locale.locator("#currency_code").fill("USD");
    await locale.locator("[name=currency_decimals]").selectOption("2");
    await locale.locator("#number_locale").fill("en_US");
    await Promise.all([
        page.waitForResponse((response) => response.url().includes("/config/saveLocale") && response.ok()),
        locale.locator("input[type=submit]").click(),
    ]);

    await ui.goto(`${base}/config`);
    await ui.click(page.locator('a[href="#tax_tab"]'));
    const tax = page.locator("#tax_config_form");
    await tax.waitFor({ state: "visible" });
    await tax
        .locator("#use_destination_based_tax")
        .uncheck()
        .catch(() => {});
    await tax
        .locator("#tax_included")
        .uncheck()
        .catch(() => {});
    await tax.locator("#default_tax_1_name").fill("TVA");
    await tax.locator("#default_tax_1_rate").fill("11");
    await tax.locator("#default_tax_2_rate").fill("");
    await tax.locator("#lbp_exchange_rate").fill("89500");
    await Promise.all([
        page.waitForResponse((response) => response.url().includes("/config/saveTax") && response.ok()),
        tax.locator("input[type=submit]").click(),
    ]);

    await ui.goto(`${base}/config`);
    await ui.click(page.locator('a[href="#barcode_tab"]'));
    const barcodeForm = page.locator("#barcode_config_form");
    await barcodeForm.waitFor({ state: "visible" });
    await barcodeForm.locator("[name=barcode_generate_if_empty]").check();
    await Promise.all([
        page.waitForResponse((response) => response.url().includes("/config/saveBarcode") && response.ok()),
        barcodeForm.locator("input[type=submit]").click(),
    ]);

    await ui.goto(`${base}/employees`);
    const existing = sql(
        ctx.mysqlContainer,
        "SELECT person_id FROM ospos_employees WHERE username='qacashier' LIMIT 1",
    );
    const personId = existing.rows?.[0];
    if (personId) {
        const row = page.locator(`#table tr[data-uniqueid="${personId}"]`);
        await row.waitFor({ state: "visible", timeout: 10000 });
        await ui.click(row.locator("a.modal-dlg").first());
    } else {
        const add = page.locator('[data-href="employees/view"]').first();
        await add.waitFor({ state: "visible" });
        await ui.click(add);
    }
    const form = page.locator("#employee_form");
    await form.waitFor({ state: "visible", timeout: 12000 });
    if (!personId) {
        await form.locator("#first_name").fill("كاشير");
        await form.locator("#last_name").fill("QA");
    }
    await ui.click(form.locator('a[href="#employee_login_info"]'));
    if (!personId) {
        await form.locator("#username").fill("qacashier");
        await form.locator("#password").fill("QACashier2026");
        await form.locator("#repeat_password").fill("QACashier2026");
    }
    await form.locator("[name=language]").selectOption("ar-LB:arabic");
    if (!personId) {
        // The Employee model assigns its standard cashier grants when no grant fields are posted.
        // Exclude the admin-prefilled checkboxes so the employee cannot inherit admin access.
        await form.locator("#permission_list input[type=checkbox]").evaluateAll((inputs) =>
            inputs.forEach((input) => {
                input.disabled = true;
            }),
        );
    }
    const submit = page
        .locator(
            '.bootstrap-dialog-footer-buttons button[id="submit"], ' +
                '.bootstrap-dialog-footer-buttons button[data-id="submit"]',
        )
        .last();
    await submit.waitFor({ state: "visible" });
    await Promise.all([
        page.waitForResponse((response) => /\/employees\/save\//.test(response.url()) && response.ok(), {
            timeout: 10000,
        }),
        ui.click(submit),
    ]);
    await page.waitForTimeout(300);

    const settings = sql(
        ctx.mysqlContainer,
        `SELECT \`key\`, \`value\` FROM ospos_app_config
            WHERE \`key\` IN ('default_tax_1_rate', 'default_tax_1_name',
                'barcode_generate_if_empty', 'language', 'currency_decimals')
            ORDER BY \`key\``,
    );
    const employee = sql(
        ctx.mysqlContainer,
        `SELECT e.person_id, e.username, e.language,
            GROUP_CONCAT(DISTINCT p.permission_id ORDER BY p.permission_id)
            FROM ospos_employees e
            JOIN ospos_grants g ON g.person_id = e.person_id
            JOIN ospos_permissions p ON p.permission_id = g.permission_id
            WHERE e.username = 'qacashier' GROUP BY e.person_id`,
    );
    const settingsText = settings.rows?.join("\n") || "";
    const employeeText = employee.rows?.join("\n") || "";
    const ok =
        settings.ok &&
        /default_tax_1_rate\t11(?:\.000)?/.test(settingsText) &&
        /default_tax_1_name\tTVA/.test(settingsText) &&
        /barcode_generate_if_empty\t1/.test(settingsText) &&
        employee.ok &&
        /qacashier\tarabic/.test(employeeText) &&
        /items/.test(employeeText) &&
        /sales/.test(employeeText) &&
        !/\bconfig\b|\bemployees\b/.test(employeeText);
    if (!ok)
        throw new Error(
            `SETUP SQL verification failed. settings=${JSON.stringify(settings)} employee=${JSON.stringify(employee)}`,
        );
    return {
        status: "pass",
        expected: "Arabic shop locale, TVA 11%, barcode generation, Arabic cashier with standard grants",
        actual: { settings: settings.rows, employee: employee.rows },
        sqlEvidence: { settings, employee },
    };
}
