<?php

/**
 * Returns true when a quantity would be stored as zero in a three-decimal column.
 *
 * Values smaller than half of 0.001 round to zero when they are saved.
 */
function is_quantity_zero(mixed $quantity): bool
{
    return abs((float) $quantity) < 0.0005;
}
