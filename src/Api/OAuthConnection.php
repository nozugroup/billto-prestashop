<?php

namespace BillTo\PrestaShop\Api;

use BillTo\PrestaShop\Support\Config;
use BillTo\PrestaShop\Support\Logger;
use BillTo\Shop\OAuth\OAuthClient;
use BillTo\Shop\OAuth\TokenSet;

/**
 * Stan połączenia OAuth tego sklepu z BillTo: własne poświadczenia instalacji i para tokenów.
 *
 * DLACZEGO TEN SKLEP MA WŁASNE POŚWIADCZENIA: moduł jest rozpowszechniany, więc jeden sekret
 * wspólny dla wszystkich instalacji oznaczałby, że wyciek u jednego sklepu otwiera konta
 * wszystkich pozostałych. Dynamic Client Registration daje każdej instalacji własną parę,
 * a wszystkie wywodzą się z jednego oświadczenia wydanego dostawcy - dzięki czemu BillTo wciąż
 * wie, z kim rozmawiać przed zmianą niezgodną wstecz.
 *
 * ODŚWIEŻANIE jest tutaj, a nie w kliencie API: token trzeba wymienić ZANIM poleci żądanie,
 * inaczej każde wygaśnięcie kosztuje jedno nieudane wystawienie faktury - a sklep zdążył już
 * powiedzieć klientowi, że się nie udało.
 */
final class OAuthConnection
{
    const KEY_CLIENT_ID = 'OAUTH_CLIENT_ID';

    const KEY_CLIENT_SECRET = 'OAUTH_CLIENT_SECRET';

    const KEY_ACCESS_TOKEN = 'OAUTH_ACCESS_TOKEN';

    const KEY_REFRESH_TOKEN = 'OAUTH_REFRESH_TOKEN';

    const KEY_EXPIRES_AT = 'OAUTH_EXPIRES_AT';

    const KEY_COMPANY = 'OAUTH_COMPANY';

    /** @var Config */
    private $config;

    /** @var Logger */
    private $logger;

    /** @var OAuthClient */
    private $oauth;

    public function __construct(Config $config, Logger $logger, OAuthClient $oauth)
    {
        $this->config = $config;
        $this->logger = $logger;
        $this->oauth = $oauth;
    }

    public function isConnected(): bool
    {
        return $this->tokens() !== null;
    }

    public function companyName(): string
    {
        return (string) $this->config->get(self::KEY_COMPANY);
    }

    public function clientId(): string
    {
        return (string) $this->config->get(self::KEY_CLIENT_ID);
    }

    public function clientSecret(): string
    {
        return (string) $this->config->get(self::KEY_CLIENT_SECRET);
    }

    /**
     * Rejestruje tę instalację w BillTo, jeżeli jeszcze nie ma własnych poświadczeń.
     *
     * Idempotentne: ponowne wywołanie nie tworzy drugiego klienta. Gdyby tworzyło, każde
     * kliknięcie „Połącz" zostawiałoby w rejestrze BillTo osierocony wpis.
     */
    public function ensureRegistered(string $registrationCode, string $redirectUri, string $installationName): bool
    {
        if ($this->clientId() !== '' && $this->clientSecret() !== '') {
            return true;
        }

        $credentials = $this->oauth->registerWithCode($registrationCode, $installationName, $redirectUri);

        if ($credentials === null) {
            $this->logger->error('[BillTo OAuth] rejestracja instalacji odrzucona przez BillTo');

            return false;
        }

        $this->config->set(self::KEY_CLIENT_ID, $credentials['client_id']);
        $this->config->set(self::KEY_CLIENT_SECRET, $credentials['client_secret']);

        return true;
    }

    /**
     * Wymienia kod autoryzacyjny na parę tokenów i zapisuje ją.
     *
     * Opakowane tutaj, a nie w kontrolerze, żeby kontroler frontowy nie musiał znać
     * identyfikatora i sekretu instalacji - te zostają w jednym miejscu.
     */
    public function exchange(string $code, string $verifier, string $redirectUri): bool
    {
        $tokens = $this->oauth->exchangeCode(
            $this->clientId(),
            $this->clientSecret(),
            $redirectUri,
            $code,
            $verifier
        );

        if ($tokens === null) {
            $this->logger->error('[BillTo OAuth] wymiana kodu autoryzacyjnego odrzucona');

            return false;
        }

        $this->store($tokens);

        return true;
    }

    /**
     * @param array{access_token: string, refresh_token: string|null, expires_in: int} $response
     */
    public function store(array $response, string $companyName = ''): void
    {
        $set = TokenSet::fromTokenResponse($response);

        $this->config->set(self::KEY_ACCESS_TOKEN, $set->accessToken);
        $this->config->set(self::KEY_REFRESH_TOKEN, (string) $set->refreshToken);
        $this->config->set(self::KEY_EXPIRES_AT, (string) $set->expiresAt);

        if ($companyName !== '') {
            $this->config->set(self::KEY_COMPANY, $companyName);
        }
    }

    /**
     * Ważny token dostępowy albo null, gdy sklep nie jest połączony lub odświeżenie padło.
     *
     * Zwrócenie null jest ŚWIADOME zamiast rzucania: wywołujący ma wtedy szansę spróbować
     * starego tokenu z konfiguracji (sklepy sprzed przejścia na OAuth) zamiast zatrzymać
     * wystawianie faktur.
     */
    public function accessToken(): ?string
    {
        $set = $this->tokens();

        if ($set === null) {
            return null;
        }

        if (!$set->needsRefresh()) {
            return $set->accessToken;
        }

        if (!$set->canRefresh()) {
            // Bez tokenu odświeżania sklepikarz musi autoryzować ponownie ręcznie - i musi
            // się o tym dowiedzieć, a nie oglądać serię niewyjaśnionych 401.
            $this->logger->error('[BillTo OAuth] token wygasł i nie ma czym go odświeżyć - wymagana ponowna autoryzacja');

            return null;
        }

        $response = $this->oauth->refresh($this->clientId(), $this->clientSecret(), (string) $set->refreshToken);

        if ($response === null) {
            $this->logger->error('[BillTo OAuth] odświeżenie tokenu odrzucone - wymagana ponowna autoryzacja');

            return null;
        }

        // Token odświeżania jest JEDNORAZOWY - BillTo unieważnia go przy wymianie. Nową parę
        // trzeba zapisać, zanim poleci kolejne żądanie, inaczej integracja sama się zablokuje.
        $this->store($response);

        return $response['access_token'];
    }

    public function disconnect(): void
    {
        foreach ([self::KEY_ACCESS_TOKEN, self::KEY_REFRESH_TOKEN, self::KEY_EXPIRES_AT, self::KEY_COMPANY] as $key) {
            $this->config->set($key, '');
        }

        // Poświadczenia instalacji (client_id/secret) ZOSTAJĄ: odłączenie to cofnięcie zgody
        // na firmę, nie wyrejestrowanie sklepu. Ponowne połączenie ma pominąć rejestrację.
    }

    private function tokens(): ?TokenSet
    {
        return TokenSet::fromArray([
            'access_token' => (string) $this->config->get(self::KEY_ACCESS_TOKEN),
            'refresh_token' => (string) $this->config->get(self::KEY_REFRESH_TOKEN),
            'expires_at' => (int) $this->config->get(self::KEY_EXPIRES_AT),
        ]);
    }
}
