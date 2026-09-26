<?php

namespace App\Libraries;

/**
 * Reads and validates the shop-wide till layout setting.
 */
final class Till_layout
{
    /**
     * Returns the saved layout, using the shop layout for missing or invalid values.
     */
    public static function get_layout(array $config): string
    {
        return self::normalize($config['till_layout'] ?? 'shop');
    }

    /**
     * Checks whether an item belongs to the configured add-on category in restaurant mode.
     */
    public static function is_addon_category(?string $item_category, array $config): bool
    {
        if (self::get_layout($config) !== 'restaurant') {
            return false;
        }

        $addon_category = trim((string) ($config['till_addon_category'] ?? ''));
        if ($addon_category === '') {
            return false;
        }

        return strcasecmp(trim($item_category ?? ''), $addon_category) === 0;
    }

    /**
     * Places the configured add-on category after regular restaurant menu categories.
     */
    public static function order_categories(array $categories, array $config): array
    {
        $ordered_categories = [];
        $addon_categories   = [];

        foreach ($categories as $category => $items) {
            if (self::is_addon_category((string) $category, $config)) {
                $addon_categories[$category] = $items;
            } else {
                $ordered_categories[$category] = $items;
            }
        }

        foreach ($addon_categories as $category => $items) {
            $ordered_categories[$category] = $items;
        }

        return $ordered_categories;
    }

    /**
     * Accepts only the supported till layouts and maps every other value to shop.
     */
    public static function normalize(mixed $layout): string
    {
        return $layout === 'restaurant' ? 'restaurant' : 'shop';
    }
}
