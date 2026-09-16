<?php

namespace BillTo\Shop;

/**
 * Builds the `User-Agent` header the BillTo REST API expects from integrations.
 *
 * Format:
 *
 *     <Product>/<version> (+<contact>; compat=<api-version>) [extra tokens]
 *     billto-woocommerce/1.4.2 (+https://billto.pl/integracje/woocommerce; compat=2026-09-15) WordPress/6.5
 *
 * The contact segment is required. `compat` is optional and declares the API version the
 * integration was tested against; it does not select API behaviour. Extra tokens describing
 * the host platform are appended after the parenthesis.
 */
final class UserAgent
{
    /**
     * @param  string  $product  stable product name, e.g. "billto-woocommerce"; constant across releases
     * @param  string  $version  version of this deployment
     * @param  string  $contact  working e-mail address or a page with a contact channel
     * @param  string|null  $compat  API version this release was tested against, or null
     * @param  string[]  $extra  additional diagnostic tokens, e.g. ["WordPress/6.5", "PHP/8.2"]
     */
    public static function build(string $product, string $version, string $contact, ?string $compat = null, array $extra = []): string
    {
        $header = self::sanitizeToken($product).'/'.self::sanitizeToken($version);

        $meta = '+'.self::sanitizeContact($contact);

        if ($compat !== null && self::sanitizeToken($compat) !== '') {
            $meta .= '; compat='.self::sanitizeToken($compat);
        }

        $header .= ' ('.$meta.')';

        foreach ($extra as $token) {
            $token = self::sanitizeToken($token);

            if ($token !== '') {
                $header .= ' '.$token;
            }
        }

        return $header;
    }

    /**
     * Strips characters that would break the header structure, in particular parentheses and
     * semicolons, and truncates the result to 60 characters.
     *
     * @param  string  $value
     */
    private static function sanitizeToken(string $value): string
    {
        $value = preg_replace('/[^A-Za-z0-9._\-\/:+]/', '', (string) $value);

        return $value === null ? '' : substr($value, 0, 60);
    }

    /**
     * @param  string  $value
     */
    private static function sanitizeContact(string $value): string
    {
        $value = preg_replace('/[^A-Za-z0-9._\-\/:@+?=&%]/', '', (string) $value);

        return $value === null ? '' : substr($value, 0, 180);
    }
}
