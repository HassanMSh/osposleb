# Receiving deliveries in the shop

Use this guide to record supplier deliveries in the shop through Receivings.
It gives the owner two answers every month: how much was paid for stock, and how much profit was made.
LL means Lebanese pounds. Screen names are given in English, with the Arabic label in brackets.

## Why use Receivings

- Adding stock with the Items inventory button records only a quantity. It saves no price paid, so no report can say how much was spent on stock.
- With Items only, the wholesale price must be edited by hand every time the supplier changes the price. If someone forgets, the profit report is wrong.
- Receivings saves every delivery with its lines, prices, and LL total, and shows it in Reports.
- With the average setting on, Receivings updates the item's wholesale price by itself.
- Old sales never change. Each sale keeps the wholesale and retail price it was sold at.

## The one rule

- Every delivery goes through Receivings. Never add delivered stock with the Items inventory button.
- The Items inventory button is only for corrections: shelf counts, breakage, expired goods, and gifts.
- If staff mix the two, the spending report misses money and the average wholesale price goes wrong.

## One-time setup

1. Open Settings > General and tick `Calc avg. Price (Receiving)` (`حساب متوسط سعر الأصناف المستلمة`). Save.
2. Check that the LL exchange rate in Settings is correct. Receivings refuses to finish without a valid rate.
3. The administrator already has Receivings. For any other employee who receives deliveries, open Employees, edit the employee, tick Receivings and the stock location, and save.
4. A new cashier does not get Receivings unless an administrator ticks it.

## Add a new product

1. Open Items and create the product as usual: name, category, barcode, retail price (`السعر`), and TVA.
2. Leave `Non-stock` unticked so the quantity is tracked.
3. Set the stock quantity to 0. Do not type the delivered quantity here.
4. The wholesale price (`سعر التكلفة`) can stay at 0. The first receiving sets it.
5. Then record the delivery in Receivings as below.

## Record a delivery

1. From Home, open Receivings (`استلام الأصناف`).
2. Check that `Receiving Mode` (`وضع الإستلام`) is `Receive` (`إستلام`).
3. Scan or type each product in `Find or Scan Item` (`بحث/مسح باركود صنف`).
4. For each line, enter the quantity and the LL price paid for one unit, not the price of the whole box.
5. Example: a pack of 24 cans for 1,440,000 LL is quantity 24 at 60,000 LL each.
6. Optional: type the supplier's invoice number in `Reference` (`رقم المرجع`) so the delivery can be found later.
7. Check every line against the supplier's invoice. Scans are added in order. If you press Finish or change a receiving control while scans are still being added, that action runs after the scans finish and the cart updates. If an add fails or shows a warning while an action waits, check the cart and press that action again. If the cart line changes while an edit or delete waits, choose the line again from the current cart. If a reload notice appears, scan again any item it says may be missing.
8. Press `Finish` (`إنهاء`).
9. Stock goes up by the received quantities, and the wholesale price becomes the average of the old stock and the new delivery.
10. A negative price or a quantity of 0 is refused.

## When the supplier changes the price

1. Record the delivery in Receivings with the new price. Do not edit the wholesale price by hand.
2. If the shop wants a new selling price, open Items and change only the retail price.
3. Earlier sales keep their old prices.

## Return goods to the supplier

1. Open Receivings and set `Receiving Mode` to `Return` (`إرتجاع لمورد`).
2. Scan the products, enter the quantity sent back and the price that was paid.
3. Press `Finish`. Stock goes down and the return is recorded in the report.

## Month-end reports

1. Money paid for stock: Reports > Detailed Reports > Receivings (`تقرير مفصل لاستلام البضاعة`). Pick the month. Each delivery shows its LL total, and the footer shows the month total.
2. Profit: Reports > Summary Reports > Items (`تقرير ملخص الأصناف`). Pick the month and read the `Wholesale` and `Profit` columns.
3. In that report, the `Wholesale Price` and `Retail Price` columns show today's item prices, not the prices of each sale. Use the totals on the right.
4. Stock left on the shelf: Reports > Inventory Reports > Inventory Summary (`تقرير ملخص المخزن`). It shows quantity times today's wholesale price.
5. Money paid and cost of goods sold are different numbers. The difference is the stock still on the shelf.

## Worked example

Tested on 2026-09-29 at 90,000 LL per dollar with a Pepsi 330ml item that started with stock 0.

| Step | Action | Stock | Wholesale price |
|---|---|---|---|
| 1 | Receive 10 at 90,000 LL ($1.00), total 900,000 LL | 10 | $1.00 |
| 2 | Sell 5 at $2.00 | 5 | $1.00 |
| 3 | Receive 10 at 135,000 LL ($1.50), total 1,350,000 LL, then change the retail price to $2.50 in Items | 15 | $1.33 |
| 4 | Sell 10 at $2.50 | 5 | $1.33 |

- Receivings report: 900,000 + 1,350,000 = 2,250,000 LL ($25) paid for Pepsi.
- Items summary: 15 sold, subtotal $35.00, wholesale $18.30, profit $16.70.
- Inventory summary: 5 left at $1.33, which is $6.65.
- The average price is rounded to 2 decimals ($1.3333 is saved as $1.33), so $18.30 + $6.65 is $24.95 instead of $25.

## Limits

- The average price does not track which batch each unit came from. When old stock sells after a price change, profit is close but not exact. In the example the exact profit is $17.50.
- Stock added through Items before the shop starts using Receivings has no purchase record. The spending report starts from the first receiving.
- If the shelf count is wrong or below zero, the average price goes wrong. Correct counts with the Items inventory button when they are found.
- Purchases are saved without a supplier. Use `Reference` for the supplier's invoice number.
