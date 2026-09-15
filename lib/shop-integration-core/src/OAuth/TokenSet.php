<?php

namespace BillTo\Shop\OAuth;

/**
 * Stored credentials of a connected shop, plus the one decision every caller needs:
 * is this token still usable, or must it be refreshed first?
 *
 * The margin exists because the alternative is worse than it looks. Refreshing only after a
 * 401 means every expiry costs one failed request - and if that request was an invoice being
 * issued, the shop has already told the customer it failed. Refreshing slightly early turns
 * the whole class of problem into a non-event.
 */
final class TokenSet
{
    /**
     * Refresh this long before the declared expiry. Covers clock drift between the shop and
     * BillTo (a minute or two is common on shared hosting) and the duration of a slow request.
     */
    const REFRESH_MARGIN_SECONDS = 300;

    /** @var string */
    public $accessToken;

    /** @var string|null */
    public $refreshToken;

    /** @var int unix timestamp */
    public $expiresAt;

    public function __construct(string $accessToken, ?string $refreshToken, int $expiresAt)
    {
        $this->accessToken = $accessToken;
        $this->refreshToken = $refreshToken;
        $this->expiresAt = $expiresAt;
    }

    /**
     * @param  array{access_token: string, refresh_token: ?string, expires_in: int}  $response
     */
    public static function fromTokenResponse(array $response, ?int $now = null): self
    {
        $now = $now ?? time();

        return new self(
            $response['access_token'],
            $response['refresh_token'],
            $now + max(0, (int) $response['expires_in'])
        );
    }

    public function needsRefresh(?int $now = null): bool
    {
        return ($now ?? time()) >= ($this->expiresAt - self::REFRESH_MARGIN_SECONDS);
    }

    /** Without a refresh token the merchant has to authorise the shop again by hand. */
    public function canRefresh(): bool
    {
        return $this->refreshToken !== null && $this->refreshToken !== '';
    }

    /**
     * @return array{access_token: string, refresh_token: ?string, expires_at: int}
     */
    public function toArray(): array
    {
        return [
            'access_token' => $this->accessToken,
            'refresh_token' => $this->refreshToken,
            'expires_at' => $this->expiresAt,
        ];
    }

    /**
     * @param  array<string, mixed>  $stored
     */
    public static function fromArray(array $stored): ?self
    {
        if (! isset($stored['access_token']) || ! is_string($stored['access_token']) || $stored['access_token'] === '') {
            return null;
        }

        $refresh = isset($stored['refresh_token']) && is_string($stored['refresh_token']) ? $stored['refresh_token'] : null;

        return new self($stored['access_token'], $refresh, (int) ($stored['expires_at'] ?? 0));
    }
}
