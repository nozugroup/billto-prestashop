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
     * Registers this installation as an OAuth client (RFC 7591) - the LAST-RESORT path.
     *
     * Most integrations never touch this. They are registered once in BillTo, get one
     * client_id/secret and run the ordinary Authorization Code flow. Dynamic registration is for
     * software that genuinely needs its OWN credentials in every installation, and BillTo hands
     * out the software statement id only to vendors who asked for it and explained why.
     *
     * Two things travel here and neither works without the other:
     *
     * - `software_statement_id` says WHO is registering. It is issued by BillTo to the vendor and
     *   shipped in the package. It is not a secret and does not need to be: on its own it opens
     *   nothing, because registration without a merchant's code is refused.
     * - `code` says WHOSE data. The merchant generates it in BillTo behind a password check; it
     *   lives two minutes and works once.
     *
     * `clientName` must be unique across BillTo and cannot be changed later - build it from
     * something durable (the customer's domain, an installation id, a date).
     *
     * @return array{client_id: string, client_secret: string}|null null when registration was refused
     */
    public function register(
        string $softwareStatementId,
        string $code,
        string $clientName,
        string $redirectUri
    ): ?array {
        $response = $this->http->post($this->baseUrl.'/oauth/register', [
            'software_statement_id' => $softwareStatementId,
            'code' => $code,
            'client_name' => $clientName,
            'redirect_uris[0]' => $redirectUri,
        ], ['Accept' => 'application/json']);

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
