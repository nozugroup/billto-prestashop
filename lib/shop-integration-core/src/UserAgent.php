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
 * Why the API asks for this: when a backwards-incompatible change is coming, BillTo needs to
 * reach the *vendor of the software*, not every shop running it. A default library header such
 * as `GuzzleHttp/7` or `WordPress/6.5` identifies the runtime, never the integration - so it
 * does not answer the only question the header exists to answer.
 *
 * The contact segment is mandatory: without it the header names a product nobody can be
 * notified about. `compat` is optional and informational - it declares the API version the
 * integration was tested against and is used to target change notifications, never to select
 * API behaviour.
 *
 * Extra tokens (host platform and its version) are appended after the parenthesis. BillTo
 * ignores them when matching, but they shorten support conversations considerably.
 */
final class UserAgent
{
    /**
     * @param  string  $product  stable product name, e.g. "billto-woocommerce" - must NOT change between releases
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
     * Strips characters that would break the header, in particular the parenthesis and the
     * semicolon used as structure. A shop can put almost anything in its version string
     * (WordPress plugins are routinely patched by hosting providers), so nothing that reaches
     * this class is trusted verbatim.
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
