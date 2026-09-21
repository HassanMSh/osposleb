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
    ];

    /**
     * Maps permissions that open a navigation module to their menu group.
     *
     * @var array<string, string>
     */
    public const MENU_GROUPS = [
        'office'     => 'home',
        'items'      => 'home',
        'reports'    => 'home',
        'sales'      => 'home',
        'home'       => 'office',
        'config'     => 'office',
        'employees'  => 'office',
        'taxes'      => 'office',
        'attributes' => 'office',
    ];

    /**
     * Permissions that grant access to administration and must be denied to
     * non-administrators.
     *
     * These IDs are the module-level permission rows in the approved OSPOS
     * schema. They are kept here as a policy ceiling, not as a grant list.
     *
     * @var list<string>
     */
    public const ADMINISTRATIVE_GRANTS = [
        'config',
        'employees',
        'taxes',
        'attributes',
        'office',
    ];

    /**
     * Standard permissions for a new cashier account.
     *
     * Active stock-location permissions are added at runtime.
     *
     * @var list<string>
     */
    public const STANDARD_CASHIER_GRANTS = [
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

    /**
     * Returns the navigation group for a permission, or no navigation group for a subpermission.
     */
    public static function menu_group(string $permission_id): string
    {
        return self::MENU_GROUPS[$permission_id] ?? '--';
    }
}
