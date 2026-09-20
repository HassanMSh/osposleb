<?php

namespace Config;

/**
 * Defines the modules and grants kept by the shop lockdown.
 */
class ShopLockdown
{
    /**
     * Module IDs that must not be exposed by the shop application.
     *
     * @var list<string>
     */
    public const REMOVED_MODULES = [
        'customers',
        'item_kits',
        'suppliers',
        'receivings',
        'giftcards',
        'messages',
        'expenses',
        'expenses_categories',
        'cashups',
        'office',
    ];

    /**
     * Permissions granted to every non-admin employee.
     *
     * @var list<string>
     */
    public const NON_ADMIN_GRANTS = [
        'items',
        'reports',
        'reports_items',
        'reports_inventory',
        'reports_sales',
        'reports_sales_taxes',
        'reports_taxes',
        'reports_payments',
        'reports_categories',
        'sales',
        'home',
    ];
}
