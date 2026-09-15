<?php

namespace BillTo\Shop\OAuth;

/**
 * Proof Key for Code Exchange (RFC 7636).
 *
 * Why a shop plugin uses PKCE even though it holds a client secret: the authorization code
 * travels through the merchant's browser. On a shop running plain HTTP internally, behind a
 * shared proxy, or with a chatty referrer policy, that code can leak - and a leaked code plus
 * a stolen secret is a full account takeover. PKCE binds the code to the request that started
 * the flow, so a code lifted in transit is useless without the verifier, which never leaves
 * the server.
 */
final class Pkce
{
    /**
     * Generates a code verifier: 43-128 characters from the unreserved set (RFC 7636 §4.1).
     *
     * Uses the cryptographic RNG - `mt_rand()` and `uniqid()` are predictable and would defeat
     * the entire point of the exchange.
     */
    public static function verifier(): string
    {
        return self::base64Url(random_bytes(64));
    }

    /** S256 challenge derived from the verifier. The plain method is not used - it adds nothing. */
    public static function challenge(string $verifier): string
    {
        return self::base64Url(hash('sha256', $verifier, true));
    }

    /** Opaque value tying the callback to the request that started it (CSRF protection). */
    public static function state(): string
    {
        return self::base64Url(random_bytes(32));
    }

    private static function base64Url(string $bytes): string
    {
        return rtrim(strtr(base64_encode($bytes), '+/', '-_'), '=');
    }
}
