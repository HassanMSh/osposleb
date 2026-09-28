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
2. Find or scan the ingredient item.
3. Enter the quantity and the LL price for one item unit.
4. For 5 kg of chicken costing 425,000 LL total, enter quantity 5 and a unit price of 85,000 LL per kg.
5. At an exchange rate of 89,500 LL per dollar, that purchase stores a $4.75 total and a rounded 425,000 LL total.
6. Complete the receiving without selecting a supplier.
7. OSPOS adds the received quantity to stock.
8. If `Calc avg. Price (Receiving)` in Settings > General is ticked, the item's cost price changes to the average of the old cost and the new purchase price. If it is off, the cost price does not change.
9. A negative price or a quantity of 0 is refused.

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
