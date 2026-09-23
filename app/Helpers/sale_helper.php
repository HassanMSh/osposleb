<?php

/**
 * Encodes a payment id for use in a URL path segment.
 *
 * @param string $payment_id Payment type name.
 *
 * @return string URL-safe base64 without padding.
 */
function encode_payment_id(string $payment_id): string
{
    return rtrim(strtr(base64_encode($payment_id), '+/', '-_'), '=');
}

/**
 * Decodes a URL-safe payment id from a URL path segment.
 *
 * @param string $encoded_payment_id URL-safe base64 without padding.
 *
 * @return false|string The payment type name, or false when the value is invalid.
 */
function decode_payment_id(string $encoded_payment_id): false|string
{
    if ($encoded_payment_id === '' || preg_match('/\A[a-z0-9_\-]+\z/i', $encoded_payment_id) !== 1 || strlen($encoded_payment_id) % 4 === 1) {
        return false;
    }

    $base64 = strtr($encoded_payment_id, '-_', '+/');
    $base64 .= str_repeat('=', (4 - strlen($base64) % 4) % 4);

    return base64_decode($base64, true);
}
