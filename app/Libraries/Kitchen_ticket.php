<?php

namespace App\Libraries;

/**
 * Applies the rules for printing restaurant kitchen tickets.
 */
class Kitchen_ticket
{
    /**
     * Says whether a receipt belongs to a completed, normal restaurant sale.
     */
    public static function is_eligible(array $config, mixed $sale_status, mixed $sale_type): bool
    {
        return Till_layout::get_layout($config) === 'restaurant'
            && $sale_status !== null
            && $sale_status == COMPLETED    // TODO: === ?
            && $sale_type !== null
            && $sale_type == SALE_TYPE_POS;    // TODO: === ?
    }
}
