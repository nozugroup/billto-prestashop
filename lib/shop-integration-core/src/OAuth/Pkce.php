<?php

namespace BillTo\Shop\OAuth;

/**
 * Proof Key for Code Exchange (RFC 7636).
 *
 * Binds the authorization code to the request that started the flow: the code is only
 * redeemable together with the verifier, which never leaves the server.
 */
final class Pkce
{
    /**
     * Generates a code verifier: 43-128 characters from the unreserved set (RFC 7636 §4.1),
     * drawn from the cryptographic RNG.
     */
    public static function verifier(): string
    {
        return self::base64Url(random_bytes(64));
    }

    /** S256 challenge derived from the verifier. */
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
