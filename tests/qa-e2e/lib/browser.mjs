/** Count user input actions while driving the page with Playwright. */
export function makeBrowser(page, getMeasurement) {
    return {
        async click(locator) {
            const m = getMeasurement();
            if (m) m.clicks++;
            await locator.click({ timeout: 12000 });
        },
        async fill(locator, value) {
            const m = getMeasurement();
            if (m) m.keys += String(value).length;
            await locator.fill(String(value), { timeout: 12000 });
        },
        async press(locator, key) {
            const m = getMeasurement();
            if (m) m.keys++;
            await locator.press(key, { timeout: 12000 });
        },
        async type(locator, value, options = {}) {
            const m = getMeasurement();
            if (m) m.keys += String(value).length;
            await locator.pressSequentially(String(value), options);
        },
        async goto(url) {
            await page.goto(url, { waitUntil: "domcontentloaded", timeout: 25000 });
        },
        async focusCheck() {
            const m = getMeasurement();
            if (m)
                m.focusChecks.push(
                    await page
                        .locator("#item")
                        .evaluate((el) => document.activeElement === el)
                        .catch(() => false),
                );
        },
    };
}
