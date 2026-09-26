# Cashier QA scenarios

This file lists the cashier journeys to check before a release: creating items and making sales.

Every scenario has an ID. The browser suite in `tests/qa-e2e/` runs the same IDs automatically, so a manual tester and the script report against the same list.

The expected money values assume dollar storage with two decimals, item-form prices entered in LBP, TVA 11% added on top of the price, and an exchange rate of 89,500 LL per dollar.

## Run the automated suite

Use a throwaway copy of the shop, never the live shop database. The suite creates items, employees, and sales, and it changes settings.

```bash
cd tests/qa-e2e
npm install
npm run qa -- --base http://127.0.0.1:18098 --mysql-container <mysql container name>
```

- `--base` is the address of the throwaway shop.
- `--mysql-container` is optional. With it, the suite checks saved values in the database and records the database queries made during each scenario. The database must have its slow query log turned on with a zero time limit for the query counts to appear.
- `--only ITEM-01,SALE-*` runs a subset.
- Results go to `tests/qa-e2e/out/<time>/`: `summary.md` for people, `results.json` for tools, plus screenshots and query logs.
- Run the suite with the app in production mode. Development mode loads about 140 separate script and style files per page and a debug toolbar, so its timings mean nothing.

## Setup

The suite does these steps first. A manual tester does them once.

| ID | Who | What to do |
|---|---|---|
| SETUP-1 | Admin | Set the shop language to Arabic (Lebanon), time zone to Asia/Beirut, currency `$`, two decimals. |
| SETUP-2 | Admin | Set the default tax name to `TVA` and rate to `11`. Leave "tax included" off. Set the pound rate to 89,500. |
| SETUP-3 | Admin | Turn on "generate a barcode if empty". |
| SETUP-4 | Admin | Create a cashier employee with Arabic as their language and the normal cashier permissions only. |
| SETUP-5 | Cashier | Create the test items: a $10.00 taxable item with a known barcode, a $10.00 tax-exempt item with an Arabic name, a $0.00 item, an out-of-stock item, a serialized item, and an item whose barcode starts with zeros. |

## Creating items

The cashier opens Items, clicks New Item, fills the form, and saves. Start each row from a valid item: name, category, cost, price, and stock filled.

| ID | What the cashier does | Expected result |
|---|---|---|
| ITEM-01 | Creates a normal item: name, category, cost 626500 LL, retail price 895000 LL, stock 5. | The item saves and appears in the list with $7.00 cost and $10.00 retail price in storage. |
| ITEM-02 | Leaves the name empty. | The form shows an error and does not save. |
| ITEM-03 | Leaves the category empty. | The form shows an error and does not save. |
| ITEM-04 | Leaves cost, price, stock, receiving quantity, or reorder level empty, one at a time. | Each empty field blocks the save. |
| ITEM-05 | Enters cost 0. | Saves with cost 0.00. |
| ITEM-06 | Enters price 0. | Saves with price 0.00. |
| ITEM-07 | Enters cost -1 LL. | The form and server reject the negative cost without saving. |
| ITEM-08 | Enters retail price -1 LL. | The form and server reject the negative price without saving. |
| ITEM-09 | Enters a price of 100000000000001. | The form refuses it. |
| ITEM-10 | Enters 940000.50, then 940000,50. | The dot form stores $10.50 and reopens at 940000 LL; record what the comma form does. |
| ITEM-11 | Uses an Arabic name, such as `حليب طازج`. | Saves and can be found by searching in Arabic. |
| ITEM-12 | Uses a mixed name, such as `حليب UHT 1L`. | Saves and displays in the right order. |
| ITEM-13 | Uses a 255-character Arabic name, then a 256-character name. | The 255-character name saves in full; the 256-character name shows an error and does not save. |
| ITEM-14 | Puts HTML and a script tag in the name and description. | The text shows as plain text and nothing runs. |
| ITEM-15 | Leaves the barcode empty. | A barcode starting with `20` is generated. |
| ITEM-16 | Uses a barcode that another item already has. | The form reports the duplicate and does not save it. |
| ITEM-17 | Uses barcode `0012345`. | The leading zeros are kept. |
| ITEM-18 | Uses a barcode with spaces, such as ` 12 34 `. | Record what is stored. Today the spaces are kept. |
| ITEM-19 | Uses a barcode with punctuation, such as `AB-12/34`. | Record what is stored and whether a scan finds it. |
| ITEM-20 | Picks a supplier. | Suppliers are locked out in this shop, so only "None" is offered. |
| ITEM-21 | Sets stock for two locations. | Each location keeps its own number. The shop has one location today. |
| ITEM-22 | Sets stock to 0, then -1, then 1.5. | Record what is accepted and how it displays. |
| ITEM-23 | Sets receiving quantity to 0, then 3. | 0 becomes 1. 3 is kept. |
| ITEM-24 | Sets reorder level to 0, then 5. | Both are kept. |
| ITEM-25 | Writes a description in Arabic and English. | The text is kept. |
| ITEM-26 | Adds a PNG picture and a JPEG picture, then removes one. | Both thumbnails show, and Remove works. |
| ITEM-27 | Ticks "allow alternative description". | In a sale, the line description can be edited. |
| ITEM-28 | Ticks "serialized". | In a sale, the line asks for a serial number and the quantity cannot be changed. |
| ITEM-29 | Keeps the default TVA. | A $10.00 sale totals $11.10. |
| ITEM-30 | Sets its own TVA of 5%. | A $10.00 sale totals $10.50. |
| ITEM-31 | Sets two taxes of its own. | Both show in the sale. |
| ITEM-32 | Marks the item tax-exempt, then zero-rated. | No TVA is charged, and the receipt shows the right marker. |
| ITEM-33 | Enters its own TVA as blank, -1, 101, and text. | Each one is refused. |
| ITEM-34 | Admin turns on destination tax, then HSN codes. | The extra field appears and is saved. |
| ITEM-35 | Chooses each stock type and item type. | The choice is saved. The kit type is disabled. |
| ITEM-36 | Admin turns on multi-pack. The cashier opens New Item. | The form opens and the pack fields save. |
| ITEM-37 | Admin creates a custom attribute. The cashier fills it. | The value is saved. |
| ITEM-38 | Ticks "deleted". | The item leaves the normal list and shows under the deleted filter. |
| ITEM-39 | Edits one item, then bulk-edits the category of two items. | Only the chosen items change. |
| ITEM-40 | Imports items from the CSV template. Looks for a copy item button. | The import works. There is no copy button. |

## Making sales

The cashier opens the register. "Scan" means typing the barcode and pressing Enter, which is what a barcode scanner does. Use the $10.00 taxable item unless the row says otherwise.

| ID | What the cashier does | Expected result |
|---|---|---|
| SALE-01 | Logs in and opens the register. | The register is in Arabic, right to left, with the cursor in the item box. |
| SALE-02 | Scans a known barcode. | One line. Total $11.10. |
| SALE-03 | Scans two barcodes very quickly. | Both are added once, in order. |
| SALE-04 | Scans the same item twice. | Quantity 2. Total $22.20. |
| SALE-05 | Searches an Arabic name and picks the result. | The right item is added. |
| SALE-06 | Searches an English name and picks the result. | The right item is added. |
| SALE-07 | Scans an unknown barcode. | An error shows and the cart does not change. |
| SALE-08 | Presses Enter with an empty item box. | Nothing happens. |
| SALE-09 | Scans the barcode with leading zeros. | The right item is added. |
| SALE-10 | Scans the same item 5 times while the server is slow. | Quantity 5. No scan is lost. |
| SALE-11 | Scans more than 20 times while the server is slow. | Scans beyond the limit are dropped with a clear warning, and the register reloads. |
| SALE-12 | Changes a line quantity to 3. | Total $33.30. |
| SALE-13 | Changes a line quantity to 0.5. | Record what happens. If allowed, the total is $5.55. |
| SALE-14 | Changes a line quantity to 0, then -1. | Record what happens. |
| SALE-15 | A normal cashier looks at the taxable line price. | The base price shows 895000 LL with muted $10.00; the customer-paid unit shows 993000 LL with muted $11.10, and the price cannot be edited. |
| SALE-16 | A cashier with the price-change permission sets the price to 1790000 LL. | The stored base price is $20.00, the total is $22.20, and the customer-paid unit shows 1987000 LL. |
| SALE-17 | Looks for a percent discount on a line. | There is no line discount in this shop. |
| SALE-18 | Looks for a fixed discount on a line. | There is no line discount in this shop. |
| SALE-19 | Looks for a discount on the whole sale. | There is no sale discount in this shop. |
| SALE-20 | Admin sets a default 10% discount. The cashier scans the item. | Total $9.99. |
| SALE-21 | Admin sets a default $1 discount. The cashier scans the item. | Total $9.99. |
| SALE-22 | Adds two items and removes the first. | Only the second remains. |
| SALE-23 | Scans the out-of-stock item. | A warning shows. Record whether the sale can finish. |
| SALE-24 | Sells the $0.00 item. | Total $0.00. The sale finishes without payment. |
| SALE-25 | Enters a serial number on the serialized item. | The serial is saved on the sale. |
| SALE-26 | Changes the alternative description on a line. | The new description is saved. |
| SALE-27 | Creates a new item from the register. | The item is added to the cart. |
| SALE-28 | Looks for a customer picker. | There is none. Sales are walk-in only. |
| SALE-29 | Finishes a sale with no customer. | The sale saves. |
| SALE-30 | Enters $11.10 cash and presses Complete. | The sale saves in one action with no change; the receipt shows $11.10 and 993000 LL. |
| SALE-31 | Enters $20.00 cash and presses Complete. | The sale saves in one action and the receipt shows $8.90 change; the change helper shows 797000 LL. |
| SALE-32 | Enters $5.00 cash and presses Complete. | $6.10 and 545500 LL remain due, Complete remains available, and no receipt appears. |
| SALE-33 | With $6.10 still due, enters $6.10 and presses Complete again. | The sale saves and the receipt appears. |
| SALE-34 | Enters $5.00 cash and presses Complete, then deletes that payment. | The payment is removed and the full amount due returns. |
| SALE-35 | Looks for card or cheque payment. | Cash only in this shop. |
| SALE-36 | Looks for a pay-later option. | There is none. |
| SALE-37 | Looks for gift card or rewards. | There is none. |
| SALE-38 | Scans the $10.00 exempt item, confirms its unit and sale total show 895000 LL, then enters 1000000 in the pound change helper. | Shows $1.17 and 105000 LL change. No payment is recorded. |
| SALE-39 | Scans the same item, then enters 20 in the dollar change helper. | Shows $10.00 and 895000 LL change. |
| SALE-40 | Looks for a way to record a payment in pounds. | There is none. Payments are recorded in dollars. |
| SALE-41 | Suspends the sale. | The register empties and the sale is held. |
| SALE-42 | Opens held sales and resumes it. | The cart comes back. |
| SALE-43 | Cancels a sale. | One confirmation, then the cart empties. |
| SALE-44 | Finishes a sale and reprints the receipt. | Both receipts show the sale number, items, total, and cashier. |
| SALE-45 | Turns "print after sale" off and on. | The setting controls whether printing starts. |
| SALE-46 | Looks for invoice mode. | Only sale, quote, and return are offered. |
| SALE-47 | Makes a quote. | A quote is saved without payment. |
| SALE-48 | In return mode, scans an item and presses Complete with the prefilled negative cash amount. | The return saves with a negative line and a negative cash payment. |
| SALE-49 | In return mode, enters `POS <number>` of an earlier sale. | The earlier lines come back as negative lines. |
| SALE-50 | A cashier tries to delete a finished sale, then the admin does. | The cashier is refused. The admin succeeds and the stock goes back. |

Money checks to repeat on SALE-30, SALE-44, and SALE-49:

- One taxable $10.00 item and one exempt $10.00 item total $21.10 and 1,888,000 LL from the rounded line sum.
- With "tax included" on, a $10.00 item totals $10.00, of which about $0.99 is TVA.
- Three taxable $0.05 items total $0.17, with $0.02 TVA and 15,000 LL from the rounded line sum.

## Speed and repetitive work

| ID | What is measured | Result on 2026-09-23 |
|---|---|---|
| PERF-01 | Search and scan speed with 5,000 items. | About 30 to 40 ms per request on the server. |
| PERF-02 | A 10-item sale: scan 10 barcodes and press Complete with exact cash. | About 1.5 seconds and 2 clicks with the old Add Payment step. Not re-measured with one-step Complete. |
| PERF-03 | A 10-item sale where 3 lines need a quantity change. | Blocked by the line edit error. |
| PERF-04 | Creating 5 items one after another. | About 6.6 seconds, 2 clicks per item. |
| PERF-05 | The held sales list with 1, 10, and 50 held sales. | Opens without delay. |

## Not covered

- Real barcode scanners, receipt printers, and cash drawers. The suite types barcodes. It does not use a device.
- Printed paper output. The suite checks the receipt page only.
- The Windows shop computer.
