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
     * Accepts only the supported till layouts and maps every other value to shop.
     */
    public static function normalize(mixed $layout): string
    {
        return $layout === 'restaurant' ? 'restaurant' : 'shop';
    }
}
