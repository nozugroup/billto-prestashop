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

it('rejestruje instalację tylko raz', function () {
    // Gdyby rejestrowała za każdym razem, każde kliknięcie "Połącz" zostawiałoby w rejestrze
    // BillTo osierocony wpis, a sklep gubiłby wcześniejsze poświadczenia.
    $http = new QueuedPost([
        ['status' => 201, 'body' => json_encode(['client_id' => 'cid', 'client_secret' => 'sec'])],
    ]);

    $connection = psConnection($http);

    expect($connection->ensureRegistered('statement', 'https://sklep.test/cb', 'PrestaShop - sklep'))->toBeTrue()
        ->and($connection->clientId())->toBe('cid')
        ->and($connection->ensureRegistered('statement', 'https://sklep.test/cb', 'PrestaShop - sklep'))->toBeTrue()
        ->and($http->calls)->toHaveCount(1);
});

it('nie zapisuje poświadczeń, gdy BillTo odrzuci rejestrację', function () {
    $connection = psConnection(new QueuedPost([['status' => 401, 'body' => '{"error":"invalid_software_statement"}']]));

    expect($connection->ensureRegistered('zle', 'https://sklep.test/cb', 'PrestaShop'))->toBeFalse()
        ->and($connection->clientId())->toBe('');
});

it('oddaje ważny token bez odpytywania BillTo', function () {
    psConfigure([
        'OAUTH_ACCESS_TOKEN' => 'wazny',
        'OAUTH_REFRESH_TOKEN' => 'rt',
        'OAUTH_EXPIRES_AT' => (string) (time() + 3600),
    ]);

    $http = new QueuedPost([]);

    expect(psConnection($http)->accessToken())->toBe('wazny')
        ->and($http->calls)->toHaveCount(0);
});

it('odświeża token ZANIM wygaśnie i zapisuje nową parę', function () {
    // Odświeżanie dopiero po 401 kosztuje jedno nieudane żądanie na każde wygaśnięcie - a jeśli
    // to było wystawienie faktury, sklep zdążył już powiedzieć klientowi, że się nie udało.
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
        // Token odświeżania jest jednorazowy - bez zapisania nowej pary integracja zablokowałaby
        // się przy kolejnym żądaniu.
        ->and(Configuration::get(Config::PREFIX . 'OAUTH_REFRESH_TOKEN'))->toBe('nowy-rt');
});

it('zgłasza brak dostępu, gdy odświeżenie zostanie odrzucone', function () {
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

it('wymienia kod autoryzacyjny i zapisuje parę tokenów', function () {
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

it('odrzucona wymiana kodu nie zostawia sklepu w stanie połowicznie połączonym', function () {
    psConfigure(['OAUTH_CLIENT_ID' => 'cid', 'OAUTH_CLIENT_SECRET' => 'sec']);

    $connection = psConnection(new QueuedPost([['status' => 400, 'body' => '{"error":"invalid_grant"}']]));

    expect($connection->exchange('zly-kod', 'weryfikator', 'https://sklep.test/cb'))->toBeFalse()
        ->and($connection->isConnected())->toBeFalse();
});

it('odłączenie kasuje tokeny, ale zostawia poświadczenia instalacji', function () {
    // Odłączenie to cofnięcie zgody na firmę, nie wyrejestrowanie sklepu - ponowne połączenie
    // ma pominąć rejestrację, zamiast zakładać w BillTo drugi wpis.
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
