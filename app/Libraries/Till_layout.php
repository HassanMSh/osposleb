<?php

namespace App\Libraries;

/**
 * Reads and validates the shop-wide till layout setting.
 */
final class Till_layout
{
    /**
     * Longest section order that fits the app_config value column.
     */
    public const CATEGORY_ORDER_MAX_LENGTH = 500;

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
     * Orders saved restaurant sections first, keeps other sections in input order, and places add-ons last.
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

        if (self::get_layout($config) === 'restaurant') {
            $saved_categories  = self::clean_category_order((string) ($config['till_category_order'] ?? ''));
            $sorted_categories = [];

            foreach (explode("\n", $saved_categories) as $saved_category) {
                foreach ($ordered_categories as $category => $items) {
                    if (self::category_names_match((string) $category, $saved_category) && ! array_key_exists($category, $sorted_categories)) {
                        $sorted_categories[$category] = $items;
                    }
                }
            }

            foreach ($ordered_categories as $category => $items) {
                if (! array_key_exists($category, $sorted_categories)) {
                    $sorted_categories[$category] = $items;
                }
            }

            $ordered_categories = $sorted_categories;
        }

        foreach ($addon_categories as $category => $items) {
            $ordered_categories[$category] = $items;
        }

        return $ordered_categories;
    }

    /**
     * Trims section names, removes empty or repeated names, and returns one name per line.
     */
    public static function clean_category_order(string $category_order): string
    {
        $categories       = preg_split('/\R/u', $category_order) ?: [];
        $clean_categories = [];
        $seen_categories  = [];

        foreach ($categories as $category) {
            $category = trim($category);
            if ($category === '') {
                continue;
            }

            $category_key = mb_strtolower($category);
            if (isset($seen_categories[$category_key])) {
                continue;
            }

            $seen_categories[$category_key] = true;
            $clean_categories[]             = $category;
        }

        return implode("\n", $clean_categories);
    }

    /**
     * Merges saved section names with existing categories, drops stale names, and excludes add-ons.
     */
    public static function merge_category_order(array $saved_categories, array $existing_categories, ?string $addon_category = null): array
    {
        $saved_order      = self::clean_category_order(implode("\n", $saved_categories));
        $saved_categories = $saved_order === '' ? [] : explode("\n", $saved_order);
        $ordered          = [];

        foreach ($saved_categories as $saved_category) {
            foreach ($existing_categories as $existing_category) {
                $existing_category = trim((string) $existing_category);
                if ($existing_category === '' || self::category_names_match($existing_category, trim((string) $addon_category))) {
                    continue;
                }

                if (self::category_names_match($existing_category, $saved_category) && ! self::contains_category_name($ordered, $existing_category)) {
                    $ordered[] = $existing_category;
                }
            }
        }

        foreach ($existing_categories as $existing_category) {
            $existing_category = trim((string) $existing_category);
            if ($existing_category === '' || self::category_names_match($existing_category, trim((string) $addon_category))) {
                continue;
            }

            if (! self::contains_category_name($ordered, $existing_category)) {
                $ordered[] = $existing_category;
            }
        }

        return $ordered;
    }

    /**
     * Returns the section order to save, or null when an edited list is too long.
     * An unchanged pre-filled list that is too long keeps the saved order, so other General settings still save.
     */
    public static function category_order_to_save(string $posted_order, string $prefilled_order, string $saved_order): ?string
    {
        $posted_order = self::clean_category_order($posted_order);
        if (mb_strlen($posted_order) <= self::CATEGORY_ORDER_MAX_LENGTH) {
            return $posted_order;
        }

        if ($posted_order === self::clean_category_order($prefilled_order)) {
            return self::clean_category_order($saved_order);
        }

        return null;
    }

    /**
     * Checks whether two category names match after trimming spaces and ignoring letter case.
     */
    private static function category_names_match(string $first_category, string $second_category): bool
    {
        return mb_strtolower(trim($first_category)) === mb_strtolower(trim($second_category));
    }

    /**
     * Checks whether an ordered list already contains a category name, ignoring case and spaces.
     */
    private static function contains_category_name(array $categories, string $category_name): bool
    {
        foreach ($categories as $category) {
            if (self::category_names_match((string) $category, $category_name)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Accepts only the supported till layouts and maps every other value to shop.
     */
    public static function normalize(mixed $layout): string
    {
        return $layout === 'restaurant' ? 'restaurant' : 'shop';
    }
}
