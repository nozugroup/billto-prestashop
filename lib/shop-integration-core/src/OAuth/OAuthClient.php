<?php

namespace BillTo\Shop\OAuth;

/**
 * BillTo OAuth 2.0 client shared by the shop plugins.
 *
 * Replaces the "paste your API token into the plugin settings" flow. The difference matters:
 * a pasted token is a long-lived credential for the merchant's whole company, copied into a
 * shop's database by hand and impossible for them to scope or audit. With OAuth the merchant
 * approves a named application for ONE company, sees exactly which permissions it asked for,
 * and can withdraw the access from BillTo without touching the shop.
 *
 * Each installation registers its own credentials through Dynamic Client Registration, so one
 * leaked secret never affects other shops running the same plugin.
 *
 * Transport is injected - see {@see HttpPostInterface}.
 */
final class OAuthClient
{
    /** @var string */
    private $baseUrl;

    /** @var HttpPostInterface */
    private $http;

    public function __construct(string $baseUrl, HttpPostInterface $http)
    {
        $this->baseUrl = rtrim($baseUrl, '/');
        $this->http = $http;
    }

    /**
     * Registers this installation as an OAuth client (RFC 7591).
     *
     * The software statement is issued to the VENDOR and embedded in the distributed code; it
     * is what lets BillTo trace every installation back to one responsible party. It is not a
     * secret granting access on its own - it only authorises creating credentials.
     *
     * @return array{client_id: string, client_secret: string}|null null when registration was refused
     */
    public function register(string $softwareStatement, string $clientName, string $redirectUri): ?array
    {
        return $this->registerWith(['software_statement' => $softwareStatement], $clientName, $redirectUri);
    }

    /**
     * Registers this installation using a one-time code the merchant generated in BillTo.
     *
     * This is the default path. Two separate things travel here, and keeping them apart is the
     * whole design:
     *
     * - The CODE is the merchant's permission: "one installation may create credentials on my
     *   company". It is issued by a signed-in BillTo user behind a password check, lives for
     *   minutes and works once, so nothing inside the distributed package grants anything.
     * - The STATEMENT is the vendor's signed identity, and it is public by necessity - the
     *   package is a zip anyone can download. On its own it registers nothing; it only decides
     *   whether the consent screen shows this vendor's verified name or a warning that BillTo
     *   does not know who wrote this software.
     *
     * @return array{client_id: string, client_secret: string}|null
     */
    public function registerWithCode(
        string $registrationCode,
        string $clientName,
        string $redirectUri,
        string $softwareStatement = ''
    ): ?array {
        $credential = ['registration_code' => $registrationCode];

        if ($softwareStatement !== '') {
            $credential['software_statement'] = $softwareStatement;
        }

        return $this->registerWith($credential, $clientName, $redirectUri);
    }

    /**
     * @param  array<string, string>  $credential
     * @return array{client_id: string, client_secret: string}|null
     */
    private function registerWith(array $credential, string $clientName, string $redirectUri): ?array
    {
        $response = $this->http->post($this->baseUrl.'/oauth/register', array_merge($credential, [
            'client_name' => $clientName,
            'redirect_uris[0]' => $redirectUri,
        ]), ['Accept' => 'application/json']);

        if ($response === null || $response['status'] !== 201) {
            return null;
        }

        $data = json_decode($response['body'], true);

        if (! is_array($data) || ! isset($data['client_id'], $data['client_secret'])) {
            return null;
        }

        return [
            'client_id' => (string) $data['client_id'],
            'client_secret' => (string) $data['client_secret'],
        ];
    }

    /**
     * URL the merchant is sent to in order to approve the integration. They pick the company
     * there - the resulting token works on that company only.
     *
     * @param  string[]  $scopes
     */
    public function authorizationUrl(
        string $clientId,
        string $redirectUri,
        array $scopes,
        string $state,
        string $codeVerifier
    ): string {
        $query = http_build_query([
            'client_id' => $clientId,
            'redirect_uri' => $redirectUri,
            'response_type' => 'code',
            'scope' => implode(' ', $scopes),
            'state' => $state,
            'code_challenge' => Pkce::challenge($codeVerifier),
            'code_challenge_method' => 'S256',
        ], '', '&');

        return $this->baseUrl.'/oauth/authorize?'.$query;
    }

    /**
     * Exchanges the authorization code for tokens.
     *
     * @return array{access_token: string, refresh_token: ?string, expires_in: int}|null
     */
    public function exchangeCode(
        string $clientId,
        string $clientSecret,
        string $redirectUri,
        string $code,
        string $codeVerifier
    ): ?array {
        return $this->token([
            'grant_type' => 'authorization_code',
            'client_id' => $clientId,
            'client_secret' => $clientSecret,
            'redirect_uri' => $redirectUri,
            'code' => $code,
            'code_verifier' => $codeVerifier,
        ]);
    }

    /**
     * Exchanges a refresh token for a new pair.
     *
     * The refresh token is SINGLE USE - BillTo revokes it as part of the exchange. Store the
     * new pair before the next request, or the integration locks itself out.
     *
     * @return array{access_token: string, refresh_token: ?string, expires_in: int}|null
     */
    public function refresh(string $clientId, string $clientSecret, string $refreshToken): ?array
    {
        return $this->token([
            'grant_type' => 'refresh_token',
            'client_id' => $clientId,
            'client_secret' => $clientSecret,
            'refresh_token' => $refreshToken,
        ]);
    }

    /**
     * @param  array<string, string>  $fields
     * @return array{access_token: string, refresh_token: ?string, expires_in: int}|null
     */
    private function token(array $fields): ?array
    {
        $response = $this->http->post($this->baseUrl.'/oauth/token', $fields, ['Accept' => 'application/json']);

        if ($response === null || $response['status'] !== 200) {
            return null;
        }

        $data = json_decode($response['body'], true);

        if (! is_array($data) || ! isset($data['access_token'])) {
            return null;
        }

        return [
            'access_token' => (string) $data['access_token'],
            'refresh_token' => isset($data['refresh_token']) ? (string) $data['refresh_token'] : null,
            // Odd default on purpose: a missing expiry is an unexpected response shape, and
            // assuming a long life would leave the plugin using a dead token for hours.
            'expires_in' => isset($data['expires_in']) ? (int) $data['expires_in'] : 0,
        ];
    }
}
