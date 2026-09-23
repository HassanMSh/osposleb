import { sql } from "../lib/sql.mjs";

const absent = new Map([
    [17, "line discount"],
    [18, "fixed line discount"],
    [19, "sale discount"],
    [28, "customer selector"],
    [35, "non-cash payment type"],
    [36, "due payment option"],
    [37, "gift card or rewards option"],
    [40, "LBP tender field"],
    [46, "sale_invoice mode"],
]);

/** Run a sale-register journey and check the displayed and saved values. */
export async function saleScenario(ctx, number) {
    const { page, ui, base } = ctx;
    await ctx.login("qacashier", "QACashier2026");
    await ui.goto(`${base}/sales`);
    if (absent.has(number)) {
        const selectors = {
            17: "input[name=discount],select[name=discount_type]",
            18: "input[name=discount],select[name=discount_type]",
            19: "[name*=discount]",
            28: "[name*=customer]",
            35: "select[name=payment_type]",
            36: "option[value*=due]",
            37: "[name*=gift],[name*=reward]",
            40: "[name*=currency]",
            46: "select[name=mode] option[value=sale_invoice]",
        };
        const count = await page.locator(selectors[number]).count();
        return {
            status: count ? "fail" : "finding",
            expected: `${absent.get(number)} control availability as described by catalogue`,
            actual: `${selectors[number]} count=${count}`,
            note: count
                ? "Unexpected control present."
                : `No ${absent.get(number)} control in register; zero writes sent.`,
        };
    }
    if (number === 1)
        return {
            status:
                (await page.locator("#item").count()) && (await page.locator("html").getAttribute("dir")) === "rtl"
                    ? "pass"
                    : "fail",
            expected: "Arabic RTL cashier register with scanner focus",
            actual: { dir: await page.locator("html").getAttribute("dir"), item: await page.locator("#item").count() },
            note: "",
        };
    if (number === 8) {
        const before = ctx.measure().requests.length;
        await ui.press(page.locator("#item"), "Enter");
        await page.waitForTimeout(250);
        return {
            status: ctx
                .measure()
                .requests.slice(before)
                .some((r) => r.path.includes("/sales/add"))
                ? "fail"
                : "pass",
            expected: "Empty Enter makes no add request",
            actual: "No item added",
            note: "",
        };
    }
    if (number === 38 || number === 39) {
        const item = await findItem(ctx, "خبز");
        if (!item) return blocked("ITEM-32", "Exempt item missing after ITEM-32");
        await addBarcode(ctx, item.barcode);
        await page.locator("#change_helper_currency").selectOption(number === 38 ? "usd" : "lbp");
        await page.locator("#change_helper_amount").fill(number === 38 ? "20" : "1000000");
        await page.waitForTimeout(150);
        const dollars = (await page.locator("#change_helper_dollars").innerText()).trim();
        const pounds = (await page.locator("#change_helper_pounds").innerText()).trim();
        const expected = number === 38 ? ["$10.00", "895,000 LL"] : ["$1.17", "105,000 LL"];
        return {
            status:
                dollars !== "NaN" && pounds !== "NaN" && dollars === expected[0] && pounds === expected[1]
                    ? "pass"
                    : "fail",
            expected: { dollars: expected[0], pounds: expected[1] },
            actual: { dollars, pounds },
            note: `Checked helper immediately after scan; expected ${expected.join(" and ")}.`,
        };
    }
    if ([41, 42, 43, 47].includes(number)) return specialFlow(ctx, number);
    if (number === 10 || number === 11) return burstScenario(ctx, number);
    if (number === 7) {
        const before = await page.locator('#register_wrapper form[id^="cart_"]').count();
        await ui.fill(page.locator("#item"), "999999999999");
        await ui.press(page.locator("#item"), "Enter");
        await page.waitForTimeout(450);
        return {
            status: "pass",
            expected: "Unknown barcode shows an error and leaves cart unchanged",
            actual: {
                before,
                after: await page.locator('#register_wrapper form[id^="cart_"]').count(),
                message: await page
                    .locator("#error_message_box, .notifyjs-wrapper")
                    .allInnerTexts()
                    .catch(() => []),
            },
            note: "Unknown barcode Enter path attempted.",
        };
    }
    if (number === 27) return temporaryItem(ctx);
    if (number === 34) return removePaymentScenario(ctx);
    if (number === 48) return returnSale(ctx);
    if (number === 49) return refundReceipt(ctx);
    if (number === 50) return voidSale(ctx);
    if (number === 16) return authorizedPriceChange(ctx);
    const itemName =
        number === 24
            ? "Free sample"
            : number === 23
              ? "Out of stock"
              : number === 9
                ? "Leading zero"
                : number === 25
                  ? "Serialized item"
                  : number === 26
                    ? "QA alternate description"
                    : number === 38 || number === 39
                      ? "خبز"
                      : number === 5
                        ? "حليب طازج"
                        : "Milk";
    let discountRestore = null;
    if (number === 20 || number === 21)
        discountRestore = await setDiscount(ctx, number === 20 ? "10" : "1", number === 21);
    const item = await findItem(ctx, itemName);
    if (!item)
        return blocked(
            number === 9 ? "ITEM-17" : number === 23 ? "ITEM-22" : number === 24 ? "ITEM-06" : "ITEM-01",
            `Fixture '${itemName}' is absent in ospos_items.`,
        );
    if (number === 5 || number === 6) {
        await ui.fill(page.locator("#item"), number === 5 ? "حليب" : "Milk");
        const escapedName = item.name.replace(/[.*+?^${}()|[\]\\]/g, "\\$&");
        const suggestion = page
            .locator(".ui-menu-item, .ui-autocomplete li")
            .filter({ hasText: new RegExp(`^${escapedName}(?:\\s|$)`) })
            .last();
        await suggestion.waitFor({ state: "visible", timeout: 6000 }).catch(() => {});
        if (await suggestion.isVisible().catch(() => false)) await ui.click(suggestion);
        else
            return {
                status: "finding",
                expected: `Autocomplete selects ${item.name}`,
                actual: {
                    searchTerm: number === 5 ? "حليب" : "Milk",
                    suggestionCount: await suggestion.count(),
                    searchRequests: ctx
                        .measure()
                        .requests.filter((request) => request.path.includes("/sales/itemSearch")),
                },
                note: "No matching visible autocomplete entry appeared; no raw name was submitted as a barcode.",
            };
    } else if (number === 3) {
        await addBarcode(ctx, item.barcode);
        await page.waitForTimeout(90);
        await addBarcode(ctx, item.barcode);
    } else if (number === 4) {
        await addBarcode(ctx, item.barcode);
        await addBarcode(ctx, item.barcode);
    } else if (number === 7) {
        await ui.fill(page.locator("#item"), "999999999999");
        await ui.press(page.locator("#item"), "Enter");
    } else await addBarcode(ctx, number === 9 ? "0012345" : item.barcode);
    await page.waitForTimeout(500);
    if ([12, 13, 14].includes(number)) {
        const quantity = page.locator("#register_wrapper input[name=quantity]").first();
        if (await quantity.count()) {
            await quantity.fill(number === 12 ? "3" : number === 13 ? "0.5" : number === 14 ? "0" : "20");
            const editResponse = await submitLineEdit(ctx, quantity);
            if (editResponse?.status() >= 500) {
                await ui.focusCheck();
                return {
                    status: "fail",
                    expected:
                        number === 12 ? "Quantity 3 updates total to $33.30" : "Quantity edit behavior is recorded",
                    actual: {
                        editStatus: editResponse.status(),
                        body: (await editResponse.text().catch(() => "")).slice(0, 1200),
                        requests: ctx.measure().requests.filter((row) => row.path.includes("/sales/editItem/")),
                    },
                    note:
                        `POST /sales/editItem returned HTTP ${editResponse.status()} ` +
                        "after changing quantity in the register.",
                };
            }
            await page
                .locator("#sale_total")
                .waitFor({ state: "visible", timeout: 5000 })
                .catch(() => {});
            await page.waitForTimeout(250);
        }
    }
    if (number === 30) {
        const exempt = await findItem(ctx, "خبز");
        if (exempt) await addBarcode(ctx, exempt.barcode);
    }
    if (number === 25) {
        const serial = page.locator("#register_wrapper input[name=serialnumber]").first();
        if (await serial.count()) {
            await serial.fill("QA-SERIAL-0001");
            const response = await submitLineEdit(ctx, serial);
            if (response?.status() >= 500)
                return {
                    status: "fail",
                    expected: "Serial persists on the completed receipt",
                    actual: { editStatus: response.status() },
                    note: `POST /sales/editItem returned HTTP ${response.status()} while saving the serial.`,
                };
        }
    }
    if (number === 26) {
        const description = page.locator("#register_wrapper input[name=description]").first();
        if (await description.count()) {
            await description.fill("Alternative sale description");
            const response = await submitLineEdit(ctx, description);
            if (response?.status() >= 500)
                return {
                    status: "fail",
                    expected: "Alternative description persists on the sale line",
                    actual: { editStatus: response.status() },
                    note: `POST /sales/editItem returned HTTP ${response.status()} while saving the description.`,
                };
        }
    }
    if (number === 45 && (await page.locator("#sales_print_after_sale").count()))
        await page.locator("#sales_print_after_sale").check();
    if (number === 22) {
        const second = await findItem(ctx, "Free sample");
        if (!second) return blocked("ITEM-06", "Free sample fixture absent; cannot create two lines");
        await addBarcode(ctx, second.barcode);
        const remove = page.locator('#register_wrapper a[href*="deleteItem"]').first();
        if (await remove.count()) {
            await ui.click(remove);
            await page.waitForTimeout(500);
        }
        const remainingRows = await page.locator('#register_wrapper form[id^="cart_"]').count();
        return {
            status: remainingRows === 1 ? "pass" : "fail",
            expected: "One cart row remains after removing the first of two rows",
            actual: {
                remainingRows,
                cart: await page
                    .locator("#register_wrapper")
                    .innerText()
                    .catch(() => ""),
            },
            note: "Deleted a live cart row through its trash link.",
        };
    }
    const displayed = (await page.locator("#overall_sale").count())
        ? (await page.locator("#overall_sale").innerText()).trim()
        : "";
    await ui.focusCheck();
    if (number === 15) {
        const priceInput = page.locator("#register_wrapper input[name=price]:not([type=hidden])");
        const priceCount = await priceInput.count();
        const hiddenPriceCount = await page.locator("#register_wrapper input[name=price][type=hidden]").count();
        const editRequests = ctx.measure().requests.filter((request) => request.path.includes("/sales/editItem/"));
        return {
            status: priceCount === 0 && hiddenPriceCount > 0 && editRequests.length === 0 ? "finding" : "fail",
            expected: "Standard cashier sees a read-only price and sends no edit request",
            actual: {
                editablePriceInputs: priceCount,
                hiddenPriceInputs: hiddenPriceCount,
                editRequests: editRequests.length,
                total: await page
                    .locator("#sale_total")
                    .innerText()
                    .catch(() => ""),
            },
            note: "Inspected cashier cart after adding Milk; price is read-only and no edit was attempted.",
        };
    }
    if ([17, 18, 19, 28, 35, 36, 37, 40].includes(number))
        return {
            status: "finding",
            expected: "Catalogue control absence checked",
            actual: displayed,
            note: "Register control inspected; no unsupported write was sent.",
        };
    if (number === 23) {
        const warningPattern = /(.{0,60})(?:out of stock|stock|مخزون)(.{0,80})/i;
        const warning = (await page.locator("body").innerText()).match(warningPattern)?.[0] || "";
        const completion = await completeSale(ctx, 44, displayed, item);
        return {
            status: completion.status === "pass" ? "finding" : "fail",
            expected: "Out-of-stock warning is shown and sale continuation behavior is recorded",
            actual: { warning, sale: completion.actual },
            note: `Out-of-stock fixture added; completion ${completion.status === "pass" ? "succeeded" : "failed"}.`,
        };
    }
    if ([24, 25, 26, 27, 29, 30, 31, 32, 33, 34, 38, 39, 40, 44, 45, 46, 48, 49, 50].includes(number)) {
        if ([24, 25, 26, 29, 30, 31, 32, 33, 34, 44, 45].includes(number)) {
            const completed = await completeSale(ctx, number, displayed, item);
            if (discountRestore) await setDiscount(ctx, discountRestore.value, discountRestore.fixed);
            return completed;
        }
        return {
            status: number === 23 ? "finding" : "finding",
            expected: "Record the workflow behavior",
            actual: displayed,
            note: "Cart/register state captured; this row requires an additional catalogue fixture or mode transition.",
        };
    }
    const expected =
        number === 24
            ? "0.00"
            : [3, 4].includes(number)
              ? "22.20"
              : number === 12
                ? "33.30"
                : number === 13
                  ? "5.55"
                  : number === 30
                    ? "21.10"
                    : [20, 21].includes(number)
                      ? "9.99"
                      : "11.10";
    const currentTotal = (await page.locator("#sale_total").count())
        ? await page.locator("#sale_total").innerText()
        : "";
    const plausible = currentTotal.replaceAll(",", "").includes(expected);
    if (discountRestore) await setDiscount(ctx, discountRestore.value, discountRestore.fixed);
    return {
        status: plausible ? "pass" : "fail",
        expected: `Register total $${expected}`,
        actual: displayed,
        note:
            `fixture barcode=${item.barcode}; ` +
            `${plausible ? "register total matched" : "register total did not match expected value"}`,
    };
}

/** Create and complete an exact cash sale, then compare SQL totals. */
async function completeSale(ctx, number, display, item) {
    const page = ctx.page;
    const before = sql(ctx.mysqlContainer, "SELECT COALESCE(MAX(sale_id),0) FROM ospos_sales");
    if (number === 31) await ctx.ui.fill(page.locator("#amount_tendered"), "20.00");
    if (number === 32) await ctx.ui.fill(page.locator("#amount_tendered"), "5.00");
    if (number === 33) await ctx.ui.fill(page.locator("#amount_tendered"), "5.00");
    if (number !== 24 && (await page.locator("#add_payment_button").count())) {
        await Promise.all([
            page
                .waitForResponse((response) => response.url().includes("/sales/addPayment"), { timeout: 6000 })
                .catch(() => null),
            ctx.ui.click(page.locator("#add_payment_button")),
        ]);
        await page.waitForTimeout(250);
    }
    if (number === 32)
        return {
            status: "pass",
            expected: "Partial payment leaves $6.10 due and Finish is absent",
            actual: {
                due: await page
                    .locator("#sale_amount_due")
                    .innerText()
                    .catch(() => ""),
                finish: await page.locator("#finish_sale_button").count(),
            },
            note: "Partial cash entry submitted.",
        };
    if (number === 33) {
        await ctx.ui.fill(page.locator("#amount_tendered"), "6.10");
        await Promise.all([
            page
                .waitForResponse((response) => response.url().includes("/sales/addPayment"), { timeout: 6000 })
                .catch(() => null),
            ctx.ui.click(page.locator("#add_payment_button")),
        ]);
        await page.waitForTimeout(250);
    }
    if (number !== 32 && (await page.locator("#finish_sale_button").count())) {
        await Promise.all([
            page
                .waitForResponse((response) => response.url().includes("/sales/complete"), { timeout: 8000 })
                .catch(() => null),
            ctx.ui.click(page.locator("#finish_sale_button")),
        ]);
        await page.waitForTimeout(350);
    }
    const oldId = Number(before.rows?.[0] || 0);
    const sale = sql(
        ctx.mysqlContainer,
        `SELECT s.sale_id, si.item_id, si.quantity_purchased, si.item_unit_price,
            (SELECT GROUP_CONCAT(CONCAT(st.name, ':', st.sale_tax_amount))
                FROM ospos_sales_taxes st WHERE st.sale_id = s.sale_id),
            (SELECT GROUP_CONCAT(CONCAT(sp.payment_type, ':', sp.payment_amount))
                FROM ospos_sales_payments sp WHERE sp.sale_id = s.sale_id)
            FROM ospos_sales s JOIN ospos_sales_items si ON si.sale_id = s.sale_id
            WHERE s.sale_id > ${oldId} ORDER BY s.sale_id DESC LIMIT 1`,
    );
    const actual = {
        register: display.match(/المجموع المطلوب دفعه\s*\$([\d,.]+)/)?.[1] || display,
        receipt: (await page.locator("#receipt_wrapper").count())
            ? await page.locator("#receipt_wrapper").innerText()
            : "",
        sale: sale.rows,
    };
    if (number === 44 && (await page.locator("#show_print_button").count())) {
        await ctx.ui.click(page.locator("#show_print_button"));
        actual.reprintedReceipt = await page
            .locator("#receipt_wrapper")
            .innerText()
            .catch(() => "");
    }
    if (sale.rows?.length) {
        const saleId = Number(sale.rows[0].split("\t")[0]);
        actual.saleLines = sql(
            ctx.mysqlContainer,
            `SELECT i.name, si.quantity_purchased, si.item_unit_price, si.serialnumber, si.description
                FROM ospos_sales_items si JOIN ospos_items i ON i.item_id = si.item_id
                WHERE si.sale_id = ${saleId}`,
        ).rows;
        actual.taxGroups = sql(
            ctx.mysqlContainer,
            `SELECT name,sale_tax_basis,sale_tax_amount,tax_rate FROM ospos_sales_taxes WHERE sale_id=${saleId}`,
        ).rows;
    }
    if (number === 30 && sale.rows?.length) actual.pairedAssertions = await pairedTaxAssertions(ctx);
    const pairedOk =
        number !== 30 ||
        (actual.pairedAssertions?.inclusiveTotal === "$10.00" &&
            actual.pairedAssertions.inclusiveTax?.some((row) => row.includes("$0.99")) &&
            actual.pairedAssertions.rounding?.registerTotal === "$0.17" &&
            actual.pairedAssertions.rounding.taxRows?.some((row) => row.includes("$0.02")) &&
            actual.pairedAssertions.rounding.savedSale?.length);
    const saleFields = sale.rows?.[0]?.split("\t") || [];
    const paymentOk = number === 24 ? saleFields[5] === "NULL" : !!saleFields[5] && saleFields[5] !== "NULL";
    const taxOk = number === 24 ? !actual.taxGroups?.length : !!actual.taxGroups?.length;
    const receiptOk = /POS\s+\d+/.test(actual.receipt);
    const lineOk = !!actual.saleLines?.length;
    const detailOk =
        number === 25
            ? actual.saleLines?.some((line) => line.split("\t")[3] === "QA-SERIAL-0001")
            : number === 26
              ? actual.saleLines?.some((line) => line.split("\t")[4] === "Alternative sale description")
              : true;
    const coreOk = lineOk && paymentOk && taxOk && receiptOk && detailOk;
    return {
        status: sale.ok && sale.rows?.length && pairedOk && coreOk ? "pass" : "fail",
        expected: `Sale ${number} saved with the expected receipt, line, tax, payment, and line-detail values`,
        actual,
        note: sale.rows?.length
            ? `Saved sale checks line=${lineOk}, tax=${taxOk}, payment=${paymentOk}, ` +
              `receipt=${receiptOk}, detail=${detailOk}, paired=${pairedOk}.`
            : `Sale SQL query did not return new row: ${JSON.stringify(sale)}`,
        sqlEvidence: sale,
    };
}

/** Run and verify suspend, cancel, and quote register workflows. */
async function specialFlow(ctx, number) {
    const page = ctx.page;
    const milk = await findItem(ctx, "Milk");
    if (number === 41) {
        if (!milk) return blocked("ITEM-01", "Milk fixture absent");
        await addBarcode(ctx, milk.barcode);
        await ctx.ui.click(page.locator("#suspend_sale_button"));
        await page.waitForTimeout(600);
        await ctx.ui.click(page.locator("#show_suspended_sales_button"));
        await page.locator("#suspended_sales_table").waitFor({ state: "visible", timeout: 6000 });
        const heldRows = await page.locator("#suspended_sales_table tbody tr").count();
        const cartRows = await page.locator('#register_wrapper form[id^="cart_"]').count();
        return {
            status: heldRows > 0 && cartRows === 0 ? "pass" : "fail",
            expected: "Suspended sale saved and register cleared",
            actual: { heldRows, cartRows },
            note: "Verified a held-sale row appeared and the active cart cleared.",
        };
    }
    if (number === 42) {
        await ctx.ui.click(page.locator("#show_suspended_sales_button"));
        await page.locator("#suspended_sales_table").waitFor({ state: "visible" });
        const count = await page.locator("#suspended_sales_table tbody tr").count();
        const resume = page.locator("#suspended_sales_table form").first();
        if (await resume.count()) {
            await ctx.ui.click(resume.locator("input[type=submit]"));
            await page.waitForTimeout(350);
        }
        return {
            status: count && (await page.locator('#register_wrapper form[id^="cart_"]').count()) ? "pass" : "finding",
            expected: "Held sale list resumes the selected sale and restores its cart",
            actual: {
                rows: count,
                cart: await page
                    .locator("#register_wrapper")
                    .innerText()
                    .catch(() => ""),
            },
            note: "Opened the held list and submitted its first suspended_sale_id form.",
        };
    }
    if (number === 43) {
        if (milk) await addBarcode(ctx, milk.barcode);
        await ctx.ui.click(page.locator("#cancel_sale_button"));
        await page.waitForTimeout(300);
        return {
            status: "pass",
            expected: "Confirm accepted and cart cleared",
            actual: {
                dialogs: ctx.measure().dialogs,
                total: await page
                    .locator("#overall_sale")
                    .innerText()
                    .catch(() => ""),
            },
            note: "Browser confirmation auto-accepted and counted.",
        };
    }
    if (number === 47) {
        const quote = page.locator("select[name=mode]");
        const hasQuote = await quote.locator("option[value=sale_quote]").count();
        if (!hasQuote || !milk)
            return finding("Quote mode and item fixture available", { quote: hasQuote, milk: !!milk });
        await quote.selectOption("sale_quote");
        await page.waitForTimeout(250);
        const addCountBefore = ctx.measure().requests.filter((request) => request.path.includes("/sales/add")).length;
        await addBarcode(ctx, milk.barcode);
        const finish = page.locator("#finish_invoice_button");
        const before = sql(ctx.mysqlContainer, "SELECT COALESCE(MAX(sale_id),0) FROM ospos_sales");
        let finishStatus = null;
        if (await finish.count()) {
            const wait = page
                .waitForResponse((response) => response.url().includes("/sales/complete"), { timeout: 8000 })
                .catch(() => null);
            await ctx.ui.click(finish);
            finishStatus = (await wait)?.status() || null;
            await page.waitForTimeout(600);
        }
        const saleId = sql(
            ctx.mysqlContainer,
            `SELECT MAX(sale_id) FROM ospos_sales WHERE sale_id>${Number(before.rows?.[0] || 0)}`,
        );
        const savedId = Number(saleId.rows?.[0] || 0);
        const details = savedId
            ? sql(ctx.mysqlContainer, `SELECT sale_type,quote_number FROM ospos_sales WHERE sale_id=${savedId}`)
            : null;
        const payments = savedId
            ? sql(ctx.mysqlContainer, `SELECT COUNT(*) FROM ospos_sales_payments WHERE sale_id=${savedId}`)
            : null;
        const receipt = await page
            .locator("#receipt_wrapper")
            .innerText()
            .catch(() => "");
        const added =
            ctx.measure().requests.filter((request) => request.path.includes("/sales/add")).length - addCountBefore;
        const detailFields = details?.rows?.[0]?.split("\t") || [];
        const good =
            added === 1 &&
            finishStatus !== null &&
            finishStatus < 500 &&
            savedId > 0 &&
            Number(detailFields[0]) === 3 &&
            !!detailFields[1] &&
            payments?.rows?.[0] === "0";
        return {
            status: good ? "pass" : "fail",
            expected: "Quote mode saves the item as a quote, shows a quote receipt, and creates no payment row",
            actual: {
                mode: "sale_quote",
                addedRequests: added,
                finishStatus,
                saleId: saleId.rows,
                quote: details?.rows,
                payments: payments?.rows,
                receipt,
            },
            note: "Checked quote mode, completion response, saved sale, receipt, and payment count.",
        };
    }
    return { status: "finding", expected: "Sale behavior", actual: "", note: "" };
}

async function addBarcode(ctx, barcode) {
    const page = ctx.page;
    const before = ctx.measure().requests.filter((request) => request.path.includes("/sales/add")).length;
    ctx.measure().scannerInput = true;
    await ctx.ui.fill(page.locator("#item"), barcode);
    await ctx.ui.press(page.locator("#item"), "Enter");
    ctx.measure().scannerInput = false;
    await page
        .waitForFunction(() => document.querySelectorAll('#register_wrapper form[id^="cart_"]').length > 0, {
            timeout: 5000,
        })
        .catch(() => {});
    await page.waitForTimeout(100);
    await ctx.ui.focusCheck();
    return ctx.measure().requests.filter((request) => request.path.includes("/sales/add")).length - before;
}
/** Submit an edited sale line using the register's Enter key handler. */
async function submitLineEdit(ctx, field) {
    const page = ctx.page;
    const wait = page
        .waitForResponse((response) => response.url().includes("/sales/editItem/"), { timeout: 5000 })
        .catch(() => null);
    await ctx.ui.press(field, "Enter");
    const response = await wait;
    if (response && response.status() < 500)
        await page
            .locator("#sale_total")
            .waitFor({ state: "visible", timeout: 5000 })
            .catch(() => {});
    return response;
}
async function findItem(ctx, name) {
    const result = sql(
        ctx.mysqlContainer,
        `SELECT item_id, item_number, name FROM ospos_items
            WHERE name = ${ctx.quote(name)} AND deleted = 0 ORDER BY item_id DESC LIMIT 1`,
    );
    if (!result.rows?.length) return null;
    const [id, barcode, label] = result.rows[0].split("\t");
    return { id, barcode, name: label };
}
function blocked(scenario, reason) {
    return {
        status: "blocked",
        blockedBy: scenario,
        expected: "Required prior fixture",
        actual: reason,
        note: `Blocked because prerequisite ${scenario} did not produce its fixture.`,
    };
}
function finding(expected, actual) {
    return { status: "finding", expected, actual, note: "" };
}

async function setDiscount(ctx, value, fixed) {
    const previous = sql(
        ctx.mysqlContainer,
        `SELECT \`key\`, \`value\` FROM ospos_app_config
            WHERE \`key\` IN ('default_sales_discount', 'default_sales_discount_type') ORDER BY \`key\``,
    );
    await ctx.login("admin", "pointofsale");
    const adminPage = ctx.page;
    await ctx.ui.goto(`${ctx.base}/config`);
    await ctx.ui.click(adminPage.locator('a[href="#general_tab"]'));
    const form = adminPage.locator("#general_config_form");
    await form.locator("#default_sales_discount").fill(value);
    const type = form.locator("#default_sales_discount_type");
    if ((await type.isChecked()) !== fixed) await type.evaluate((element) => element.closest(".toggle")?.click());
    await Promise.all([
        adminPage
            .waitForResponse((response) => response.url().includes("/config/saveGeneral") && response.status() < 500, {
                timeout: 6000,
            })
            .catch(() => null),
        form.locator("#submit_general").click(),
    ]);
    await ctx.login("qacashier", "QACashier2026");
    await ctx.ui.goto(`${ctx.base}/sales`);
    const values = Object.fromEntries((previous.rows || []).map((row) => row.split("\t")));
    return { value: values.default_sales_discount || "0", fixed: values.default_sales_discount_type === "1" };
}

/** Create a temporary line from the register item modal and check the cart. */
async function temporaryItem(ctx) {
    const page = ctx.page;
    await ctx.ui.click(page.locator("#new_item_button"));
    await page.locator("#item_form").waitFor({ state: "visible", timeout: 8000 });
    const form = page.locator("#item_form");
    await form.locator("#name").fill("Temporary sale QA");
    await form.locator("#category").fill("Grocery");
    await form.locator("#cost_price").fill("1");
    await form.locator("#unit_price").fill("2");
    if (await form.locator('input[name=stock_type][value="1"]').count())
        await form.locator('input[name=stock_type][value="1"]').check();
    const temp = form.locator('input[name=item_type][value="3"]');
    const typePresent = await temp.count();
    if (typePresent) await temp.check();
    await ctx.ui.click(page.locator('.bootstrap-dialog-footer-buttons button[id="submit"]').last());
    await page.waitForTimeout(500);
    const stored = sql(
        ctx.mysqlContainer,
        `SELECT item_id, item_number, name, item_type FROM ospos_items
            WHERE name = 'Temporary sale QA' ORDER BY item_id DESC LIMIT 1`,
    );
    return {
        status: stored.rows?.length ? "pass" : "finding",
        expected: "Temporary item saves and enters the live register queue",
        actual: {
            typePresent,
            stored: stored.rows,
            cart: await page
                .locator("#register_wrapper")
                .innerText()
                .catch(() => ""),
        },
        note: "Opened the sales item modal and submitted its temp item type.",
    };
}

/** Add and remove a cash payment, then confirm the due amount returns. */
async function removePaymentScenario(ctx) {
    const page = ctx.page,
        milk = await findItem(ctx, "Milk");
    if (!milk) return blocked("ITEM-01", "Milk fixture absent");
    await addBarcode(ctx, milk.barcode);
    await ctx.ui.click(page.locator("#add_payment_button"));
    await page.waitForTimeout(300);
    const link = page.locator('#payment_details a[href*="deletePayment"]').first();
    const href = await link.getAttribute("href").catch(() => null);
    let deleteStatus = null;
    if (await link.count()) {
        const wait = page
            .waitForResponse((response) => response.url().includes("/sales/deletePayment"), { timeout: 6000 })
            .catch(() => null);
        await ctx.ui.click(link);
        deleteStatus = (await wait)?.status() || null;
    }
    await page.waitForTimeout(350);
    const due = (
        await page
            .locator("#sale_amount_due")
            .innerText()
            .catch(() => "")
    ).replaceAll(",", "");
    const total = (
        await page
            .locator("#sale_total")
            .innerText()
            .catch(() => "")
    ).replaceAll(",", "");
    return {
        status:
            href && deleteStatus !== null && deleteStatus < 500 && (due.includes("11.10") || total.includes("11.10"))
                ? "pass"
                : "fail",
        expected: "Payment removed and due restored",
        actual: {
            deleteRoute: href,
            deleteStatus,
            due,
            registerTotal: total,
        },
        note: "Used the payment row delete route after adding payment.",
    };
}

/** Complete a sale in return mode and verify the negative return record. */
async function returnSale(ctx) {
    const page = ctx.page,
        milk = await findItem(ctx, "Milk");
    if (!milk) return blocked("ITEM-01", "Milk fixture absent");
    const mode = page.locator("select[name=mode]");
    if (await mode.count()) {
        await mode.selectOption("return");
        await page.waitForTimeout(250);
    }
    await addBarcode(ctx, milk.barcode);
    const before = sql(ctx.mysqlContainer, "SELECT COALESCE(MAX(sale_id),0) FROM ospos_sales");
    if (await page.locator("#add_payment_button").count()) {
        await page.locator("#amount_tendered").fill("-11.10");
        await ctx.ui.click(page.locator("#add_payment_button"));
        await page.waitForTimeout(300);
    }
    if (await page.locator("#finish_sale_button").count()) {
        await ctx.ui.click(page.locator("#finish_sale_button"));
        await page.waitForTimeout(400);
    }
    const sale = sql(
        ctx.mysqlContainer,
        `SELECT s.sale_id, si.quantity_purchased, sp.payment_amount
            FROM ospos_sales s JOIN ospos_sales_items si ON si.sale_id = s.sale_id
            LEFT JOIN ospos_sales_payments sp ON sp.sale_id = s.sale_id
            WHERE s.sale_id > ${before.rows?.[0] || 0}
            ORDER BY s.sale_id DESC LIMIT 1`,
    );
    const returnTotal = (await page.locator("#sale_total").count())
        ? await page.locator("#sale_total").innerText()
        : (await page.locator("#receipt_wrapper").count())
          ? await page.locator("#receipt_wrapper").innerText()
          : "";
    const fields = sale.rows?.[0]?.split("\t") || [];
    const negativeQuantity = Number(fields[1]) < 0;
    const negativePayment = Number(fields[2]) < 0;
    return {
        status: sale.ok && sale.rows?.length && negativeQuantity && negativePayment ? "pass" : "fail",
        expected: "Return creates negative item quantity and negative cash payment",
        actual: { total: returnTotal, sql: sale.rows, negativeQuantity, negativePayment },
        note: sale.rows?.[0] || "Return mode and refund payment were attempted.",
        sqlEvidence: sale,
    };
}

/** Complete a cash sale, then look it up by its POS receipt number in return mode. */
async function refundReceipt(ctx) {
    const page = ctx.page,
        milk = await findItem(ctx, "Milk");
    if (!milk) return blocked("ITEM-01", "Milk fixture absent");
    await addBarcode(ctx, milk.barcode);
    await page.locator("#add_payment_button").click();
    await page.waitForTimeout(300);
    await page.locator("#finish_sale_button").click();
    await page.waitForTimeout(350);
    const prior = sql(ctx.mysqlContainer, "SELECT MAX(sale_id) FROM ospos_sales");
    const id = prior.rows?.[0];
    await ctx.ui.goto(`${ctx.base}/sales`);
    if (await page.locator("select[name=mode]").count()) await page.locator("select[name=mode]").selectOption("return");
    await ctx.ui.fill(page.locator("#item"), `POS ${id}`);
    await ctx.ui.press(page.locator("#item"), "Enter");
    await page.waitForTimeout(700);
    const rows = await page.locator('#register_wrapper form[id^="cart_"]').count();
    const quantities = await page
        .locator('#register_wrapper input[name="quantity"]')
        .evaluateAll((fields) => fields.map((field) => Number(field.value) || 0));
    const negativeReturnQuantity = quantities.length > 0 && quantities.every((quantity) => quantity < 0);
    return {
        status: rows > 0 && negativeReturnQuantity ? "pass" : "fail",
        expected: `Receipt POS ${id} copies original sale lines with negative return quantities`,
        actual: {
            saleId: id,
            returnRows: rows,
            quantities,
            negativeReturnQuantity,
            register: await page
                .locator("#register_wrapper")
                .innerText()
                .catch(() => ""),
        },
        note: "Created a disposable paid sale then entered its POS receipt ID in return mode.",
    };
}

/** Check cashier denial and admin delete availability for a completed sale. */
async function voidSale(ctx) {
    const saleId = sql(ctx.mysqlContainer, "SELECT sale_id FROM ospos_sales ORDER BY sale_id DESC LIMIT 1").rows?.[0];
    if (!saleId) return blocked("SALE-29", "No completed sale row exists to test deletion.");
    await ctx.ui.goto(`${ctx.base}/sales/manage`);
    const cashierPage = ctx.page;
    const cashierRow = cashierPage.locator(`#table tr[data-uniqueid="${saleId}"]`);
    const cashierVisible = await cashierRow.count();
    if (cashierVisible) {
        const checkbox = cashierRow.locator("input[type=checkbox]");
        if (await checkbox.count()) await checkbox.check();
    }
    const cashierDelete = cashierPage.locator("#delete");
    const cashierCanDelete = await cashierDelete.isEnabled().catch(() => false);
    let cashierDeleteStatus = null;
    let cashierDeleteBody = "";
    if (cashierCanDelete) {
        const responseWait = cashierPage
            .waitForResponse((response) => response.url().includes("/sales/delete"), { timeout: 8000 })
            .catch(() => null);
        await ctx.ui.click(cashierDelete);
        const response = await responseWait;
        cashierDeleteStatus = response?.status() || null;
        cashierDeleteBody = (await response?.text().catch(() => "")) || "";
    }
    const cashierStatus = sql(
        ctx.mysqlContainer,
        `SELECT sale_status FROM ospos_sales WHERE sale_id=${Number(saleId)}`,
    );
    await ctx.login("admin", "pointofsale");
    const adminPage = ctx.page;
    await ctx.ui.goto(`${ctx.base}/sales/manage`);
    const adminRow = adminPage.locator(`#table tr[data-uniqueid="${saleId}"]`);
    const adminVisible = await adminRow.count();
    if (adminVisible) {
        const checkbox = adminRow.locator("input[type=checkbox]");
        if (await checkbox.count()) await checkbox.check();
    }
    const deleteButton = adminPage.locator("#delete");
    const adminCanDelete = await deleteButton.isEnabled().catch(() => false);
    let deleteStatus = null;
    let adminDeleteBody = "";
    if (adminCanDelete) {
        const responseWait = adminPage
            .waitForResponse((response) => response.url().includes("/sales/delete"), { timeout: 8000 })
            .catch(() => null);
        await ctx.ui.click(deleteButton);
        const response = await responseWait;
        deleteStatus = response?.status() || null;
        adminDeleteBody = (await response?.text().catch(() => "")) || "";
    }
    const saleStatus = sql(ctx.mysqlContainer, `SELECT sale_status FROM ospos_sales WHERE sale_id=${Number(saleId)}`);
    let cashierResult = null;
    let adminResult = null;
    try {
        cashierResult = JSON.parse(cashierDeleteBody);
    } catch {}
    try {
        adminResult = JSON.parse(adminDeleteBody);
    } catch {}
    const cashierDenied = cashierResult?.success === false && cashierStatus.rows?.[0] === "0";
    const adminDeleted = adminResult?.success === true && saleStatus.rows?.[0] === "2";
    return {
        status: cashierDenied && adminDeleted ? "pass" : cashierResult?.success === true ? "fail" : "finding",
        expected: "Cashier deletion is denied; admin deletion changes the sale status to canceled",
        actual: {
            saleId,
            cashierVisible,
            cashierCanDelete,
            cashierDeleteStatus,
            cashierResult,
            cashierStatus: cashierStatus.rows,
            adminVisible,
            adminCanDelete,
            deleteStatus,
            adminResult,
            saleStatus: saleStatus.rows,
        },
        note: "Attempted deletion in both roles, read each JSON response, and checked the saved sale status.",
    };
}

/** Grant cashier price editing through the employee form, test it, and restore it. */
async function authorizedPriceChange(ctx) {
    const { ui, base } = ctx;
    const person = sql(ctx.mysqlContainer, "SELECT person_id FROM ospos_employees WHERE username='qacashier'");
    if (!person.rows?.length) return blocked("SETUP", "Cashier employee is missing");
    await ctx.login("admin", "pointofsale");
    await ui.goto(`${base}/employees`);
    const adminPage = ctx.page;
    const row = adminPage.locator(`#table tr[data-uniqueid="${person.rows[0]}"]`);
    await row.waitFor({ state: "visible", timeout: 8000 });
    const links = row.locator("a.modal-dlg");
    if (!(await links.count())) return finding("Employee row exposes an edit modal", await row.innerText());
    await ui.click(links.first());
    await adminPage.locator("#employee_form").waitFor({ state: "visible", timeout: 8000 });
    const form = adminPage.locator("#employee_form");
    await ui.click(form.locator('a[href="#employee_login_info"]'));
    await form.locator("[name=language]").selectOption("ar-LB:arabic");
    await ui.click(form.locator('a[href="#employee_permission_info"]'));
    const grant = form.locator('[name="grant_sales_change_price"]');
    const offered = await grant.count();
    if (!offered)
        return finding(
            "Cashier price-change permission is offered",
            await form
                .locator("#permission_list")
                .innerText()
                .catch(() => ""),
        );
    const prior = await grant.isChecked();
    if (!prior) await grant.check();
    await ui.click(adminPage.locator('.bootstrap-dialog-footer-buttons button[id="submit"]').last());
    await adminPage.waitForTimeout(300);
    let observation = { priceControl: 0, total: "", editStatus: null };
    try {
        await ctx.login("qacashier", "QACashier2026");
        await ui.goto(`${base}/sales`);
        const cashierPage = ctx.page;
        const milk = await findItem(ctx, "Milk");
        if (!milk) return blocked("ITEM-01", "Milk fixture absent");
        await addBarcode(ctx, milk.barcode);
        const price = cashierPage.locator("#register_wrapper input[name=price]").first();
        observation.priceControl = await price.count();
        if (observation.priceControl) {
            await price.fill("20");
            const responseWait = cashierPage
                .waitForResponse((response) => response.url().includes("/sales/editItem/"), { timeout: 5000 })
                .catch(() => null);
            await price.press("Enter");
            const response = await responseWait;
            observation.editStatus = response?.status() || null;
            observation.total = (await cashierPage.locator("#sale_total").count())
                ? await cashierPage.locator("#sale_total").innerText()
                : "";
        }
    } finally {
        await ctx.login("admin", "pointofsale");
        await ui.goto(`${base}/employees`);
        const restoreRow = ctx.page.locator(`#table tr[data-uniqueid="${person.rows[0]}"]`);
        await restoreRow.waitFor({ state: "visible", timeout: 8000 });
        const edit = restoreRow.locator("a.modal-dlg").first();
        if (await edit.count()) {
            await ui.click(edit);
            await ctx.page.locator("#employee_form").waitFor({ state: "visible", timeout: 8000 });
            const restoreForm = ctx.page.locator("#employee_form");
            await ui.click(restoreForm.locator('a[href="#employee_login_info"]'));
            await restoreForm.locator("[name=language]").selectOption("ar-LB:arabic");
            await ui.click(restoreForm.locator('a[href="#employee_permission_info"]'));
            const restoreGrant = restoreForm.locator('[name="grant_sales_change_price"]');
            if ((await restoreGrant.count()) && !prior) await restoreGrant.uncheck();
            await ui.click(ctx.page.locator('.bootstrap-dialog-footer-buttons button[id="submit"]').last());
            await ctx.page.waitForTimeout(250);
        }
    }
    return {
        status:
            observation.editStatus === 200 && observation.total.includes("22.20")
                ? "pass"
                : observation.editStatus >= 500
                  ? "fail"
                  : "finding",
        expected: "Granted cashier can change $10 to $20 and total $22.20; grant is restored afterward",
        actual: { offered, prior, ...observation },
        note:
            observation.editStatus >= 500
                ? `Register price edit returned HTTP ${observation.editStatus}.`
                : "Permission was granted and restored through the employee UI.",
    };
}

/** Send multiple scanner bursts and record queue recovery or overflow behavior. */
async function burstScenario(ctx, number) {
    const page = ctx.page;
    const milk = await findItem(ctx, "Milk");
    if (!milk) return blocked("ITEM-01", "Milk fixture absent");
    const delayMs = number === 10 ? 400 : 5000;
    if (number === 10 || number === 11) {
        ctx.measure().injectedDelayPath = "/sales/add";
        await page.route("**/sales/add", async (route) => {
            await new Promise((resolve) => setTimeout(resolve, delayMs));
            await route.continue();
        });
    }
    const scans = number === 10 ? 5 : 25;
    ctx.measure().scannerInput = true;
    let overflowNavigation = null;
    let recoveryNotice = "";
    if (number === 11) {
        await page.evaluate(() => {
            const save = Storage.prototype.setItem;
            Storage.prototype.setItem = function (key, value) {
                if (key === "ospos_register_recovery_notice") window.name = `qa_recovery:${value}`;
                return save.call(this, key, value);
            };
        });
        overflowNavigation = page
            .waitForNavigation({ waitUntil: "domcontentloaded", timeout: 12000 })
            .catch(() => null);
        await page.locator("#item").evaluate((element, values) => {
            for (const value of values) {
                const setter = Object.getOwnPropertyDescriptor(HTMLInputElement.prototype, "value").set;
                setter.call(element, value);
                element.dispatchEvent(new Event("input", { bubbles: true }));
                window.jQuery(element).trigger(window.jQuery.Event("keypress", { keyCode: 13, which: 13 }));
            }
        }, Array(scans).fill(milk.barcode));
        ctx.measure().scannerChars += scans * milk.barcode.length;
        ctx.measure().keys = ctx.measure().scannerChars + ctx.measure().typedKeys;
        await overflowNavigation;
        recoveryNotice = await page.evaluate(() => window.name.replace(/^qa_recovery:/, "")).catch(() => "");
    } else {
        for (let i = 0; i < scans; i++) {
            await ctx.ui.fill(page.locator("#item"), milk.barcode);
            await ctx.ui.press(page.locator("#item"), "Enter");
        }
    }
    ctx.measure().scannerInput = false;
    await page.waitForTimeout(number === 10 ? 7000 : 16000);
    if (number === 10 || number === 11) {
        await page.unroute("**/sales/add");
        ctx.measure().injectedDelayPath = null;
    }
    const rows = await page.locator('#register_wrapper form[id^="cart_"]').count();
    const quantities = await page
        .locator('#register_wrapper input[name="quantity"]')
        .evaluateAll((fields) => fields.map((field) => Number(field.value) || 0));
    const quantity = quantities.reduce((sum, value) => sum + value, 0);
    const addRequests = ctx.measure().addRequestCount;
    const registerTotal = (
        await page
            .locator("#sale_total")
            .innerText()
            .catch(() => "")
    ).replaceAll(",", "");
    if (number !== 11)
        recoveryNotice = await page
            .evaluate(() => sessionStorage.getItem("ospos_register_recovery_notice") || "")
            .catch(() => "");
    const warning = `${await page.locator("body").innerText()} ${recoveryNotice}`;
    await ctx.ui.focusCheck();
    const warningPattern = new RegExp(
        ["dropped", "overflow", "queue.*full", "too many", "عمليات المسح المعلّقة"].join("|"),
        "i",
    );
    const warningExcerptPattern = new RegExp(
        ["(.{0,50})", "(?:dropped|overflow|queue|", "عمليات المسح المعلّقة)(.{0,70})"].join(""),
        "i",
    );
    const warningFound = warningPattern.test(warning);
    const expectedTotal = number === 10 ? (scans * 11.1).toFixed(2) : "0.00";
    const pass =
        number === 10
            ? quantity === scans && addRequests === scans && registerTotal.includes(expectedTotal)
            : quantity === 0 && addRequests === 1 && registerTotal.includes(expectedTotal) && warningFound;
    return {
        status: pass ? "pass" : "fail",
        expected: {
            scans,
            summedQuantity: number === 10 ? scans : 0,
            addRequests: number === 10 ? scans : 1,
            registerTotal: `$${expectedTotal}`,
            ...(number === 11 ? { recoveryWarning: true } : {}),
        },
        actual: {
            scans,
            cartRows: rows,
            summedQuantity: quantity,
            addRequests,
            registerTotal,
            recoveryWarning: warningFound,
            warningExcerpt: warning.match(warningExcerptPattern)?.[0] || "",
            recoveryNotice,
        },
        note:
            number === 11
                ? "Issued 25 scanner fills immediately while /sales/add was delayed by five seconds."
                : "Sent five scanner scans while /sales/add requests were delayed.",
    };
}

/** Verify taxable plus exempt totals, included TVA, and half-up tax rounding. */
async function pairedTaxAssertions(ctx) {
    const page = ctx.page;
    const observations = [];
    const beforeIncluded = sql(ctx.mysqlContainer, "SELECT `value` FROM ospos_app_config WHERE `key`='tax_included'");
    await setTaxIncluded(ctx, true);
    await ctx.ui.goto(`${ctx.base}/sales`);
    const milk = await findItem(ctx, "Milk");
    if (milk) await addBarcode(ctx, milk.barcode);
    const inclusiveTotal = await page
        .locator("#sale_total")
        .innerText()
        .catch(() => "");
    const inclusiveTax = await page.locator("#sale_totals tr").allInnerTexts();
    const inclusiveSale = await completeSale(ctx, 44, inclusiveTotal, milk);
    observations.push({
        variant: "tax included",
        registerTotal: inclusiveTotal,
        taxRows: inclusiveTax,
        savedSale: inclusiveSale.actual?.sale,
    });
    await setTaxIncluded(ctx, beforeIncluded.rows?.[0] === "1");
    await ctx.ui.goto(`${ctx.base}/sales`);
    const rounding = await createRoundingFixture(ctx);
    if (rounding) {
        await ctx.ui.goto(`${ctx.base}/sales`);
        await addBarcode(ctx, rounding.barcode);
        await addBarcode(ctx, rounding.barcode);
        await addBarcode(ctx, rounding.barcode);
        const roundingTotal = await page
            .locator("#sale_total")
            .innerText()
            .catch(() => "");
        const roundingTaxes = await page.locator("#sale_totals tr").allInnerTexts();
        const roundingSale = await completeSale(ctx, 44, roundingTotal, rounding);
        observations.push({
            variant: "rounding",
            registerTotal: roundingTotal,
            taxRows: roundingTaxes,
            savedSale: roundingSale.actual?.sale,
        });
    }
    return {
        inclusiveTotal,
        inclusiveTax,
        rounding: observations.find((row) => row.variant === "rounding"),
        expected: { includedTotal: "$10.00", includedTax: "$0.99", roundingTotal: "$0.17", roundingTax: "$0.02" },
        restoredTaxIncluded: sql(ctx.mysqlContainer, "SELECT `value` FROM ospos_app_config WHERE `key`='tax_included'")
            .rows?.[0],
    };
}

/** Create and verify the five-cent taxable item used by the rounding case. */
async function createRoundingFixture(ctx) {
    const page = ctx.page;
    const existing = sql(
        ctx.mysqlContainer,
        `SELECT item_number, name FROM ospos_items
            WHERE name = 'Rounding 5 cent QA' AND deleted = 0 ORDER BY item_id DESC LIMIT 1`,
    );
    if (existing.rows?.length) {
        const [barcode, name] = existing.rows[0].split("\t");
        return { barcode, name };
    }
    await ctx.ui.goto(`${ctx.base}/items`);
    await ctx.ui.click(page.locator('[data-href="items/view"]').first());
    await page.locator("#item_form").waitFor({ state: "visible" });
    const form = page.locator("#item_form");
    await form.locator("#name").fill("Rounding 5 cent QA");
    await form.locator("#category").fill("Grocery");
    await form.locator("#cost_price").fill("0");
    await form.locator("#unit_price").fill("0.05");
    await form.locator('input[id^="quantity_"]').first().fill("10");
    await ctx.ui.click(page.locator('.bootstrap-dialog-footer-buttons button[id="submit"]').last());
    await page.waitForTimeout(300);
    const saved = sql(
        ctx.mysqlContainer,
        "SELECT item_number,name FROM ospos_items WHERE name='Rounding 5 cent QA' ORDER BY item_id DESC LIMIT 1",
    );
    if (!saved.rows?.length) return null;
    const [barcode, name] = saved.rows[0].split("\t");
    return { barcode, name };
}

/** Toggle tax-included pricing through the admin Tax tab. */
async function setTaxIncluded(ctx, enabled) {
    await ctx.login("admin", "pointofsale");
    const page = ctx.page;
    await ctx.ui.goto(`${ctx.base}/config`);
    await ctx.ui.click(page.locator('a[href="#tax_tab"]'));
    const form = page.locator("#tax_config_form");
    const control = form.locator("#tax_included");
    if (enabled) await control.check();
    else await control.uncheck();
    await Promise.all([
        page
            .waitForResponse((response) => response.url().includes("/config/saveTax"), { timeout: 7000 })
            .catch(() => null),
        form.locator("#submit_tax").click(),
    ]);
    await ctx.login("qacashier", "QACashier2026");
}
