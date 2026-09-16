<?php

declare(strict_types=1);

use BillTo\PrestaShop\Api\OAuthConnection;
use BillTo\PrestaShop\Support\Config;
use BillTo\PrestaShop\Support\Logger;
use BillTo\Shop\OAuth\HttpPostInterface;
use BillTo\Shop\OAuth\OAuthClient;

/** Zwraca zaplanowane odpowiedzi po kolei i zapamiętuje, o co pytano. */
final class QueuedPost implements HttpPostInterface
{
    /** @var array<int, array{status: int, body: string}|null> */
    private $queue;

    /** @var array<int, array{url: string, fields: array<string, string>}> */
    public $calls = [];

    public function __construct(array $queue)
    {
        $this->queue = $queue;
    }

    public function post(string $url, array $fields, array $headers = []): ?array
    {
        $this->calls[] = ['url' => $url, 'fields' => $fields];

        return array_shift($this->queue);
    }
}

function psConnection(QueuedPost $http): OAuthConnection
{
    $config = new Config();

    return new OAuthConnection($config, new Logger(), new OAuthClient('https://billto.test', $http));
}

function psConfigure(array $values): void
{
    foreach ($values as $key => $value) {
        Configuration::updateValue(Config::PREFIX . $key, $value);
    }
}

it('registers the installation only once', function () {
    $http = new QueuedPost([
        ['status' => 201, 'body' => json_encode(['client_id' => 'cid', 'client_secret' => 'sec'])],
    ]);

    $connection = psConnection($http);

    expect($connection->ensureRegistered('3b8cacc7-eeec', 'blti_KOD', 'https://sklep.test/cb', 'PrestaShop sklep'))->toBeTrue()
        ->and($connection->clientId())->toBe('cid')
        ->and($connection->ensureRegistered('3b8cacc7-eeec', 'blti_KOD', 'https://sklep.test/cb', 'PrestaShop sklep'))->toBeTrue()
        ->and($http->calls)->toHaveCount(1)
        // Registration sends both the software statement id and the merchant's code.
        ->and($http->calls[0]['fields']['software_statement_id'])->toBe('3b8cacc7-eeec')
        ->and($http->calls[0]['fields']['code'])->toBe('blti_KOD');
});

it('stores no credentials when BillTo rejects the registration', function () {
    // 403 covers a wrong, used or expired code, and a vendor without DCR access.
    $connection = psConnection(new QueuedPost([['status' => 403, 'body' => '{"error":"invalid_code"}']]));

    expect($connection->ensureRegistered('3b8cacc7-eeec', 'zle', 'https://sklep.test/cb', 'PrestaShop'))->toBeFalse()
        ->and($connection->clientId())->toBe('');
});

it('returns a valid token without calling BillTo', function () {
    psConfigure([
        'OAUTH_ACCESS_TOKEN' => 'wazny',
        'OAUTH_REFRESH_TOKEN' => 'rt',
        'OAUTH_EXPIRES_AT' => (string) (time() + 3600),
    ]);

    $http = new QueuedPost([]);

    expect(psConnection($http)->accessToken())->toBe('wazny')
        ->and($http->calls)->toHaveCount(0);
});

it('refreshes the token before it expires and stores the new pair', function () {
    psConfigure([
        'OAUTH_CLIENT_ID' => 'cid',
        'OAUTH_CLIENT_SECRET' => 'sec',
        'OAUTH_ACCESS_TOKEN' => 'stary',
        'OAUTH_REFRESH_TOKEN' => 'stary-rt',
        'OAUTH_EXPIRES_AT' => (string) (time() + 120),
    ]);

    $http = new QueuedPost([
        ['status' => 200, 'body' => json_encode(['access_token' => 'nowy', 'refresh_token' => 'nowy-rt', 'expires_in' => 43200])],
    ]);

    $connection = psConnection($http);

    expect($connection->accessToken())->toBe('nowy')
        ->and($http->calls[0]['fields']['grant_type'])->toBe('refresh_token')
        // The refresh token is single use, so the new pair has to be stored.
        ->and(Configuration::get(Config::PREFIX . 'OAUTH_REFRESH_TOKEN'))->toBe('nowy-rt');
});

it('reports no access when the refresh is rejected', function () {
    psConfigure([
        'OAUTH_CLIENT_ID' => 'cid',
        'OAUTH_CLIENT_SECRET' => 'sec',
        'OAUTH_ACCESS_TOKEN' => 'stary',
        'OAUTH_REFRESH_TOKEN' => 'uniewazniony',
        'OAUTH_EXPIRES_AT' => (string) (time() - 10),
    ]);

    expect(psConnection(new QueuedPost([['status' => 400, 'body' => '{"error":"invalid_grant"}']]))->accessToken())
        ->toBeNull();
});

it('exchanges the authorization code and stores the token pair', function () {
    psConfigure(['OAUTH_CLIENT_ID' => 'cid', 'OAUTH_CLIENT_SECRET' => 'sec']);

    $http = new QueuedPost([
        ['status' => 200, 'body' => json_encode(['access_token' => 'at', 'refresh_token' => 'rt', 'expires_in' => 43200])],
    ]);

    $connection = psConnection($http);

    expect($connection->exchange('kod', 'weryfikator', 'https://sklep.test/cb'))->toBeTrue()
        ->and($http->calls[0]['fields']['grant_type'])->toBe('authorization_code')
        ->and($http->calls[0]['fields']['code_verifier'])->toBe('weryfikator')
        ->and($connection->isConnected())->toBeTrue();
});

it('leaves no half-connected state when the code exchange is rejected', function () {
    psConfigure(['OAUTH_CLIENT_ID' => 'cid', 'OAUTH_CLIENT_SECRET' => 'sec']);

    $connection = psConnection(new QueuedPost([['status' => 400, 'body' => '{"error":"invalid_grant"}']]));

    expect($connection->exchange('zly-kod', 'weryfikator', 'https://sklep.test/cb'))->toBeFalse()
        ->and($connection->isConnected())->toBeFalse();
});

it('clears the tokens on disconnect but keeps the installation credentials', function () {
    psConfigure([
        'OAUTH_CLIENT_ID' => 'cid',
        'OAUTH_CLIENT_SECRET' => 'sec',
        'OAUTH_ACCESS_TOKEN' => 'at',
        'OAUTH_REFRESH_TOKEN' => 'rt',
        'OAUTH_EXPIRES_AT' => (string) (time() + 3600),
    ]);

    $connection = psConnection(new QueuedPost([]));
    $connection->disconnect();

    expect($connection->isConnected())->toBeFalse()
        ->and($connection->clientId())->toBe('cid')
        ->and($connection->clientSecret())->toBe('sec');
});
