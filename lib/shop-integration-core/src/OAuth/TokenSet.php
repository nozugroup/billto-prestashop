<?php

namespace BillTo\Shop\OAuth;

/**
 * Stored credentials of a connected shop, with the access token, the refresh token and the
 * expiry, plus {@see needsRefresh()} answering whether the token is still usable.
 */
final class TokenSet
{
    /**
     * Seconds before the declared expiry at which the token counts as needing a refresh,
     * covering clock drift between the shop and BillTo and the duration of a slow request.
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

    /** False when no refresh token was stored; the shop then has to be authorised again. */
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
