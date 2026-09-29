# Tracking kitchen stock

Use this guide to record ingredient purchases and kitchen use in OSPOS.
The app records purchase cost and stock changes, but it does not link ingredients to menu items.
Selling a sandwich does not reduce the chicken stock quantity.
LL means Lebanese pounds.

## Set up ingredient items

1. Create one item for each ingredient, such as `Chicken (kg)`.
2. Set its category to `Kitchen stock`.
3. Leave `Non-stock` unticked so OSPOS tracks its quantity.
4. Enter its cost price and leave the retail price at zero if the item is not sold directly.
5. Open Settings > General and set `Kitchen stock category` to `Kitchen stock`.
6. Open Settings > Locale and set quantity decimals to at least 1 for quantities such as 2.5 kg.

## Give an employee access

1. Open Employees and edit the employee who records purchases.
2. Tick Receivings and each Receivings location the employee can use.
3. Save the employee.
4. A new cashier does not get Receivings unless an administrator ticks it.

## Record a purchase

1. Open Receivings and select the receiving location.
2. Find or scan the ingredient item. Fast scans are added in order. If you finish or change a receiving control while scans are still being added, that action runs after the scans finish and the cart updates. If an add fails or shows a warning while an action waits, check the cart and press that action again. If the cart line changes while an edit or delete waits, choose the line again from the current cart. If a reload notice appears, scan again any item it says may be missing.
3. Enter the quantity and the LL price for one item unit.
4. For 5 kg of chicken costing 425,000 LL total, enter quantity 5 and a unit price of 85,000 LL per kg.
5. At an exchange rate of 89,500 LL per dollar, that purchase stores a $4.75 total and a rounded 425,000 LL total.
6. Complete the receiving without selecting a supplier.
7. OSPOS adds the received quantity to stock.
8. If `Calc avg. Price (Receiving)` is ticked and stock stays above 0, the average is rounded to the nearest 1,000 LL.
9. A first delivery or a delivery made while stock is at or below 0 uses the price paid for that line.
10. If a delivery or supplier return leaves total stock at 0 or below, the old cost stays unchanged.
11. If the setting is off or the paid price matches the current cost, the cost does not change.
12. A negative price or a quantity of 0 is refused.

## Record kitchen use

1. Open Items and press the inventory button on the ingredient's row.
2. Enter a negative quantity for the ingredient that the kitchen used.
3. Add a comment that says what was used.
4. Save the inventory change.
5. Selling menu items does not change ingredient quantities.

## Check the month-end totals

1. Open Reports > Inventory to see what is left.
2. Open Reports > Receivings to see what was bought and paid.
3. New purchases show their saved LL total and dollar total.
4. Older purchases have no saved LL total, so that report cell stays blank.

## Restaurant till filter

The `Kitchen stock category` setting hides that category from the restaurant menu, search suggestions, and item entry.
Items in the category remain available in Items, Inventory, Receivings, and reports.
The setting does not hide anything on the shop till.
