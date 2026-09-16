<?php

namespace BillTo\PrestaShop\Admin;

use BillTo\PrestaShop\Api\ApiException;
use BillTo\PrestaShop\Api\Client;
use BillTo\PrestaShop\Support\Config;
use BillTo\PrestaShop\Support\Logger;
use BillTo\PrestaShop\Support\UpdateChecker;
use BillTo\PrestaShop\Sync\Queue;
use BillTo\Shop\ApiPaths;
use BillTo\Shop\Settings;
use HelperForm;
use OrderState;
use PaymentModule;
use Tools;

/**
 * Module configuration page (HelperForm) with a status panel on top.
 */
final class ConfigForm
{
    /** @var \Module */
    private $module;

    /** @var Config */
    private $config;

    public function __construct(\Module $module, Config $config)
    {
        $this->module = $module;
        $this->config = $config;
    }

    /** Timeout of the registration-metadata lookup, in seconds. */
    const STATEMENT_LOOKUP_TIMEOUT = 15;

    /**
     * Software statement id of this module, read from the BillTo instance the shop is configured
     * against.
     *
     * Production and sandbox are separate instances with separate application registries, so an
     * id issued in one does not exist in the other; a value shipped in the package would only
     * work in a single environment and would leave the custom API address unusable. Null when
     * the instance has no entry for this module, or has not granted it dynamic registration.
     *
     * @return string|null
     */
    private function softwareStatementId()
    {
        $ch = curl_init(rtrim($this->config->baseUrl(), '/') . '/plugins/prestashop/latest');

        if ($ch === false) {
            return null;
        }

        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => self::STATEMENT_LOOKUP_TIMEOUT,
            CURLOPT_HTTPHEADER => ['Accept: application/json'],
        ]);

        $body = curl_exec($ch);
        $status = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
        curl_close($ch);

        if ($status !== 200 || !is_string($body)) {
            return null;
        }

        $payload = json_decode($body, true);
        $id = is_array($payload) && isset($payload['software_statement_id']) ? $payload['software_statement_id'] : null;

        return is_string($id) && $id !== '' ? $id : null;
    }

    /** Scopes the module requests: the minimum needed to invoice orders. */
    const SCOPES = [
        'orders:read', 'orders:write',
        'invoices:read', 'invoices:write',
        'contractors:read', 'contractors:write',
        'products:read',
    ];

    public function render(): string
    {
        $output = '';

        if (Tools::isSubmit('submitBilltoConfig')) {
            $output .= $this->save();
        }

        if (Tools::isSubmit('billtoRunQueue')) {
            $processed = $this->module->queue()->runDue(50);
            $output .= $this->module->displayConfirmation(sprintf($this->module->l('Przetworzono zadań: %d', 'configform'), $processed));
        }

        if (Tools::isSubmit('billtoOauthConnect')) {
            $output .= $this->startOAuth();
        }

        if (Tools::isSubmit('billtoOauthDisconnect')) {
            $this->module->oauthConnection()->disconnect();
            $output .= $this->module->displayConfirmation($this->module->l('Odłączono sklep od BillTo.', 'configform'));
        }

        $output .= $this->oauthResultNotice();

        return $output . $this->updateNotice() . $this->oauthPanel() . $this->statusPanel() . $this->form();
    }


    /**
     * Starts the Authorization Code flow with PKCE, preceded by registration of this
     * installation. The callback is handled by `controllers/front/oauth.php`.
     */
    private function startOAuth(): string
    {
        // Registration code entered by the merchant, not a value shipped in the module archive.
        $code = trim((string) Tools::getValue('billto_registration_code'));

        if ($code === '' && $this->module->oauthConnection()->clientId() === '') {
            return $this->module->displayError($this->module->l('Wklej kod instalacyjny wygenerowany w BillTo (Ustawienia → Integracje → Autoryzowane aplikacje).', 'configform'));
        }

        $redirectUri = $this->module->oauthRedirectUri();
        $connection = $this->module->oauthConnection();

        // Leading backslash required: PrestaShop classes live in the global namespace, this
        // file does not.
        $shopName = \Configuration::get('PS_SHOP_NAME');
        // The installation name is unique across BillTo and fixed after registration. Shop name
        // plus date, so the same shop can reconnect without colliding (BillTo answers 422 on a
        // duplicate). Truncated to the 50-character limit on the BillTo side.
        $installation = substr('PrestaShop ' . ($shopName !== false && $shopName !== '' ? $shopName : 'sklep') . ' ' . gmdate('Y-m-d H:i'), 0, 50);

        $statementId = $this->softwareStatementId();

        if ($statementId === null) {
            return $this->module->displayError($this->module->l('Nie udało się pobrać danych rejestracji z BillTo. Sprawdź adres API w ustawieniach modułu i spróbuj ponownie.', 'configform'));
        }

        if (!$connection->ensureRegistered($statementId, $code, $redirectUri, (string) $installation)) {
            return $this->module->displayError($this->module->l('BillTo odrzuciło rejestrację tej instalacji. Kod instalacyjny jest jednorazowy i ważny dwie minuty - wygeneruj nowy i spróbuj ponownie.', 'configform'));
        }

        $verifier = \BillTo\Shop\OAuth\Pkce::verifier();
        $state = \BillTo\Shop\OAuth\Pkce::state();

        // The verifier and the state stay on the server; only the state travels in the
        // redirect URL.
        $this->config->set('OAUTH_STATE', $state);
        $this->config->set('OAUTH_VERIFIER', $verifier);

        // The admin return URL is stored here, where the employee session is available; admin
        // controller URLs carry its token and the front controller has no such session.
        $this->config->set('OAUTH_RETURN', \Context::getContext()->link->getAdminLink(
            'AdminModules',
            true,
            [],
            ['configure' => 'billtoinvoices']
        ));

        $url = (new \BillTo\Shop\OAuth\OAuthClient($this->config->oauthBaseUrl(), new \BillTo\PrestaShop\Support\CurlPost()))
            ->authorizationUrl($connection->clientId(), $redirectUri, self::SCOPES, $state, $verifier);

        Tools::redirect($url);

        return '';
    }

    /** Komunikat po powrocie z BillTo - kontroler frontowy przekazuje wynik parametrem. */
    private function oauthResultNotice(): string
    {
        $result = (string) Tools::getValue('billto_oauth');

        if ($result === '') {
            return '';
        }

        if ($result === 'connected') {
            return $this->module->displayConfirmation($this->module->l('Sklep połączony z BillTo.', 'configform'));
        }

        $messages = [
            'denied' => $this->module->l('Autoryzacja została przerwana - sklep nie został połączony.', 'configform'),
            'state_mismatch' => $this->module->l('Powrót z BillTo nie pasuje do rozpoczętego żądania. Rozpocznij łączenie od nowa.', 'configform'),
            'exchange_failed' => $this->module->l('Nie udało się wymienić kodu autoryzacyjnego. Spróbuj ponownie.', 'configform'),
        ];

        return $this->module->displayError(isset($messages[$result]) ? $messages[$result] : $this->module->l('Połączenie z BillTo nie powiodło się.', 'configform'));
    }

    /** Connection status panel with the connect / disconnect button. */
    private function oauthPanel(): string
    {
        $connection = $this->module->oauthConnection();
        $l = function (string $text) {
            return $this->module->l($text, 'configform');
        };

        if ($connection->isConnected()) {
            $company = $connection->companyName();

            return '<div class="panel"><h3>' . $l('Połączenie z BillTo') . '</h3>'
                . '<p><strong>' . $l('Sklep jest połączony.') . '</strong>'
                . ($company !== '' ? ' ' . htmlspecialchars($l('Firma:') . ' ' . $company, ENT_QUOTES, 'UTF-8') : '')
                . '</p>'
                . '<form method="post"><button type="submit" name="billtoOauthDisconnect" class="btn btn-default">'
                . $l('Odłącz') . '</button></form></div>';
        }

        return '<div class="panel"><h3>' . $l('Połączenie z BillTo') . '</h3>'
            . '<p>' . $l('Wygeneruj kod instalacyjny w BillTo (Ustawienia → Integracje → Autoryzowane aplikacje), wklej go poniżej i podłącz sklep. Firmę i zakres uprawnień wskażesz w BillTo - token nie jest przenoszony do sklepu.') . '</p>'
            . '<form method="post">'
            . '<input type="text" name="billto_registration_code" placeholder="blti_..." autocomplete="off" style="min-width:320px;margin-right:8px">'
            . '<button type="submit" name="billtoOauthConnect" class="btn btn-primary">'
            . $l('Połącz z BillTo') . '</button></form></div>';
    }

    private function save(): string
    {
        $errors = [];
        $baseUrlBefore = $this->config->baseUrl();

        $lists = ['PAID_STATES', 'SETTLE_STATES', 'UNPAID_MODULES'];
        $bools = ['CREATE_ON_CHECKOUT', 'KSEF_AUTO', 'REFUND_CORRECTIONS', 'EU_B2B_ENABLED', 'NON_EU_ENABLED', 'RESYNC_ON_EDIT', 'UNTAXED_EXTRAS_FOLLOW_GOODS', 'FOREIGN_TAXED_FOLLOWS_SHOP'];

        foreach (array_keys(Config::defaults()) as $key) {
            if ($key === 'CRON_TOKEN' || in_array($key, $lists, true) || in_array($key, $bools, true)) {
                continue;
            }

            $field = 'BILLTO_' . $key;

            if (!Tools::getIsset($field)) {
                continue;
            }

            $value = trim((string) Tools::getValue($field));

            $this->config->set($key, $value);
        }

        // Multi-selects are absent from the request when nothing is selected; switches when off.
        foreach ($lists as $list) {
            $value = Tools::getValue('BILLTO_' . $list);
            $items = is_array($value) ? array_map(static function ($item) use ($list) {
                return $list === 'UNPAID_MODULES' ? preg_replace('/[^a-z0-9_]/i', '', (string) $item) : (string) (int) $item;
            }, $value) : [];
            $this->config->set($list, implode(',', array_filter($items)));
        }

        foreach ($bools as $bool) {
            $this->config->set($bool, Tools::getValue('BILLTO_' . $bool) ? 1 : 0);
        }

        if ($errors !== []) {
            return $this->module->displayError(implode('<br>', $errors));
        }

        // Production, sandbox and a custom address are separate BillTo instances with separate
        // client registries, so credentials are dropped when the environment changes.
        if ($this->config->baseUrl() !== $baseUrlBefore && $this->module->oauthConnection()->isConnected()) {
            $this->module->oauthConnection()->forget();

            return $this->module->displayConfirmation($this->module->l('Ustawienia zapisane.', 'configform'))
                . $this->module->displayWarning($this->module->l('Zmiana środowiska rozłączyła sklep z BillTo. Połącz go ponownie - poświadczenia z poprzedniego środowiska tam nie działają.', 'configform'));
        }

        return $this->module->displayConfirmation($this->module->l('Ustawienia zapisane.', 'configform'));
    }

    /**
     * A newer module version - this page is the only place PrestaShop lets us mention it. A module
     * installed outside Addons never shows up in the shop's update list, and installing it is a
     * manual ZIP upload anyway, so the notice links straight to the download and the changelog.
     */
    private function updateNotice(): string
    {
        $update = (new UpdateChecker())->availableUpdate($this->module->version);

        if ($update === null) {
            return '';
        }

        $message = sprintf(
            $this->module->l('Dostępna jest nowsza wersja modułu: %s (zainstalowana: %s).', 'configform'),
            htmlspecialchars((string) $update['version']),
            htmlspecialchars((string) $this->module->version)
        );

        $links = '<a class="btn btn-default" href="' . htmlspecialchars((string) $update['download_url']) . '"><i class="icon-download"></i> '
            . $this->module->l('Pobierz ZIP', 'configform') . '</a>';

        if (!empty($update['changelog_url'])) {
            $links .= ' <a href="' . htmlspecialchars((string) $update['changelog_url']) . '" target="_blank" rel="noopener">'
                . $this->module->l('Lista zmian', 'configform') . '</a>';
        }

        return '<div class="alert alert-info"><p>' . $message . ' '
            . $this->module->l('Aktualizację wgrywasz w Moduły -> Wgraj moduł; ustawienia i powiązania zamówień zostają.', 'configform')
            . '</p><p>' . $links . '</p></div>';
    }

    private function statusPanel(): string
    {
        // The same client the synchronisation uses, so the panel describes the live connection.
        $client = $this->module->apiClient();
        $connected = $this->module->oauthConnection()->isConnected();
        $rows = [];

        $rows[] = [
            $this->module->l('Poświadczenia', 'configform'),
            $connected
                ? '<span class="text-success">&#10003; ' . $this->module->l('połączenie OAuth', 'configform') . '</span>'
                : '<span class="text-danger">' . $this->module->l('brak - sklep nie jest połączony', 'configform') . '</span>',
        ];

        if (!$client->isConfigured()) {
            $rows[] = [$this->module->l('Połączenie', 'configform'), '<span class="text-danger">' . $this->module->l('użyj „Połącz z BillTo" powyżej', 'configform') . '</span>'];
        } else {
            try {
                $series = $client->get(ApiPaths::invoiceSeries(), ['type' => 'VAT']);
                $default = null;

                foreach (isset($series['data']) ? $series['data'] : [] as $row) {
                    if (!empty($row['is_default'])) {
                        $default = isset($row['name']) ? $row['name'] : $row['code'];
                    }
                }

                // Shows the API address in use, not just the environment name.
                $rows[] = [$this->module->l('Połączenie', 'configform'), '<span class="text-success">&#10003; ' . htmlspecialchars($this->config->baseUrl()) . '</span>'];
                $rows[] = [$this->module->l('Domyślna seria VAT', 'configform'), $default !== null ? htmlspecialchars((string) $default) : '<span class="text-danger">' . $this->module->l('brak - ustaw w BillTo albo wybierz serię poniżej', 'configform') . '</span>'];
            } catch (ApiException $e) {
                $rows[] = [$this->module->l('Połączenie', 'configform'), '<span class="text-danger">' . htmlspecialchars($e->getMessage()) . '</span>'];
            }
        }

        $rows[] = [$this->module->l('Wersja modułu', 'configform'), htmlspecialchars((string) $this->module->version)];

        $stats = Queue::stats();
        $rows[] = [$this->module->l('Zadania w tle', 'configform'), sprintf('%d %s, %s %s', $stats['pending'], $this->module->l('oczekujących', 'configform'), $stats['failed'] > 0 ? '<span class="text-danger">' . $stats['failed'] . '</span>' : '0', $this->module->l('nieudanych w 7 dni', 'configform'))];

        $cronUrl = \Context::getContext()->link->getModuleLink($this->module->name, 'cron', ['token' => $this->config->cronToken()]);
        $rows[] = [$this->module->l('Adres cron', 'configform'), '<code>' . htmlspecialchars($cronUrl) . '</code><br><small>' . $this->module->l('Wywołuj co 5 minut w trybie „cron"; w trybie „od razu" cron przetwarza tylko ponowienia i odpytywanie KSeF.', 'configform') . '</small>'];

        $html = '<div class="panel"><h3><i class="icon-info"></i> ' . $this->module->l('Stan integracji', 'configform') . '</h3><table class="table"><tbody>';

        foreach ($rows as $row) {
            $html .= '<tr><th style="width:220px">' . htmlspecialchars($row[0]) . '</th><td>' . $row[1] . '</td></tr>';
        }

        $html .= '</tbody></table>';
        $html .= '<form method="post"><button type="submit" name="billtoRunQueue" class="btn btn-default"><i class="icon-refresh"></i> ' . $this->module->l('Uruchom zadania teraz', 'configform') . '</button></form></div>';

        return $html;
    }

    private function form(): string
    {
        $states = [];

        foreach (OrderState::getOrderStates((int) \Context::getContext()->language->id) as $state) {
            $states[] = ['id' => (int) $state['id_order_state'], 'name' => $state['name']];
        }

        $modules = [];

        foreach (PaymentModule::getInstalledPaymentModules() as $module) {
            $modules[] = ['id' => $module['name'], 'name' => $module['name']];
        }

        $vatTypes = [];

        foreach (['0 KR', '23', '8', '5', '0 WDT', '0 EX', 'zw', 'np I', 'np II', 'oo'] as $type) {
            $vatTypes[] = ['id' => $type, 'name' => $type];
        }

        $l = function (string $text) {
            return $this->module->l($text, 'configform');
        };

        $fields = [
            'form' => [
                'legend' => ['title' => $l('BillTo - konfiguracja'), 'icon' => 'icon-cogs'],
                'input' => [
                    // There is no API token field: the shop connects through OAuth only.
                    ['type' => 'select', 'label' => $l('Środowisko'), 'name' => 'BILLTO_ENVIRONMENT', 'options' => ['query' => [['id' => Config::ENV_PRODUCTION, 'name' => $l('Produkcja (billto.pl)')], ['id' => Config::ENV_SANDBOX, 'name' => $l('Sandbox (sandbox.billto.pl)')], ['id' => Config::ENV_CUSTOM, 'name' => $l('Własny adres API')]], 'id' => 'id', 'name' => 'name'], 'desc' => $l('Produkcja i sandbox to ODRĘBNE konta BillTo. Zmiana środowiska rozłącza sklep - połącz go ponownie.')],
                    ['type' => 'text', 'label' => $l('Własny adres API'), 'name' => 'BILLTO_CUSTOM_URL'],

                    ['type' => 'select', 'label' => $l('Dla których zamówień'), 'name' => 'BILLTO_INVOICE_MODE', 'options' => ['query' => [['id' => Config::MODE_ALL, 'name' => $l('Wszystkie zamówienia (sklep bez kasy fiskalnej)')], ['id' => Config::MODE_VAT_NUMBER, 'name' => $l('Tylko gdy klient podał NIP / numer VAT w adresie')]], 'id' => 'id', 'name' => 'name']],
                    ['type' => 'select', 'label' => $l('Pole z NIP w adresie'), 'name' => 'BILLTO_TAX_ID_FIELD', 'options' => ['query' => [['id' => 'vat_number', 'name' => 'vat_number (Numer VAT)'], ['id' => 'dni', 'name' => 'dni (Numer identyfikacyjny)']], 'id' => 'id', 'name' => 'name']],
                    ['type' => 'switch', 'label' => $l('Zamówienie w BillTo od razu po złożeniu'), 'name' => 'BILLTO_CREATE_ON_CHECKOUT', 'is_bool' => true, 'values' => $this->yesNo(), 'desc' => $l('Wyłączone: zamówienie powstaje razem z fakturą przy zapłacie.')],
                    ['type' => 'select', 'label' => $l('Statusy oznaczające zapłatę'), 'name' => 'BILLTO_PAID_STATES[]', 'multiple' => true, 'options' => ['query' => $states, 'id' => 'id', 'name' => 'name'], 'desc' => $l('Puste = statusy oznaczone w PrestaShop jako „uznaj za opłacone".')],
                    ['type' => 'select', 'label' => $l('Metody płatności odroczonej'), 'name' => 'BILLTO_UNPAID_MODULES[]', 'multiple' => true, 'options' => ['query' => $modules, 'id' => 'id', 'name' => 'name'], 'desc' => $l('Faktura wystawiana jako NIEopłacona; wpłata dopisywana po statusie poniżej.')],
                    ['type' => 'select', 'label' => $l('Statusy oznaczające odbiór płatności'), 'name' => 'BILLTO_SETTLE_STATES[]', 'multiple' => true, 'options' => ['query' => $states, 'id' => 'id', 'name' => 'name'], 'desc' => $l('Puste = statusy oznaczone jako „wysłane" / „dostarczone".')],
                    ['type' => 'switch', 'label' => $l('Wysyłaj faktury do KSeF automatycznie'), 'name' => 'BILLTO_KSEF_AUTO', 'is_bool' => true, 'values' => $this->yesNo()],
                    ['type' => 'switch', 'label' => $l('Korekty ze zwrotów (dowody zwrotu)'), 'name' => 'BILLTO_REFUND_CORRECTIONS', 'is_bool' => true, 'values' => $this->yesNo()],
                    ['type' => 'select', 'label' => $l('Zwrot samej kwoty (bez pozycji)'), 'name' => 'BILLTO_AMOUNT_REFUND_MODE', 'options' => ['query' => [['id' => Settings::REFUND_PROPORTIONAL, 'name' => $l('Korekta obniżająca cenę każdej pozycji proporcjonalnie')], ['id' => Settings::REFUND_NOTE, 'name' => $l('Tylko informacja - korektę wystawia obsługa w BillTo')]], 'id' => 'id', 'name' => 'name']],
                    ['type' => 'switch', 'label' => $l('Aktualizuj zamówienie w BillTo po edycji w panelu'), 'name' => 'BILLTO_RESYNC_ON_EDIT', 'is_bool' => true, 'values' => $this->yesNo()],

                    ['type' => 'select', 'label' => $l('Tryb kwot'), 'name' => 'BILLTO_AMOUNT_MODE', 'options' => ['query' => [['id' => Settings::AMOUNT_GROSS, 'name' => $l('Brutto - BillTo liczy netto od ceny z podatkiem (zalecane)')], ['id' => Settings::AMOUNT_NET, 'name' => $l('Netto')]], 'id' => 'id', 'name' => 'name']],
                    ['type' => 'switch', 'label' => $l('Dostawa bez podatku dziedziczy stawkę towarów'), 'name' => 'BILLTO_UNTAXED_EXTRAS_FOLLOW_GOODS', 'is_bool' => true, 'values' => $this->yesNo(), 'desc' => $l('Gdy sklep nie naliczył podatku od dostawy lub opłaty, a towary mają VAT, dostawa dostaje najwyższą stawkę towarów zamiast „zw" (świadczenie pomocnicze).')],
                    ['type' => 'select', 'label' => $l('Pozycje ujemne (rabaty koszykowe)'), 'name' => 'BILLTO_NEGATIVE_LINES', 'options' => ['query' => [['id' => Settings::NEGATIVE_DISTRIBUTE, 'name' => $l('Rozdziel rabat proporcjonalnie na pozycje')], ['id' => Settings::NEGATIVE_SKIP, 'name' => $l('Nie wysyłaj zamówienia, zapisz błąd')]], 'id' => 'id', 'name' => 'name']],
                    ['type' => 'select', 'label' => $l('Stawka 0% w sklepie'), 'name' => 'BILLTO_VAT_ZERO', 'options' => ['query' => $vatTypes, 'id' => 'id', 'name' => 'name']],
                    ['type' => 'select', 'label' => $l('Pozycja bez podatku'), 'name' => 'BILLTO_VAT_NO_TAX', 'options' => ['query' => $vatTypes, 'id' => 'id', 'name' => 'name']],

                    ['type' => 'select', 'label' => $l('Konsument z innego kraju UE'), 'name' => 'BILLTO_OSS_MODE', 'options' => ['query' => [['id' => Settings::OSS_PL_VAT, 'name' => $l('Faktura VAT ze stawkami polskimi (poniżej progu 10 000 EUR)')], ['id' => Settings::OSS_INVOICE, 'name' => $l('Faktura OSS ze stawką kraju konsumpcji (rejestracja OSS)')], ['id' => Settings::OSS_OFF, 'name' => $l('Nie wystawiaj faktury')]], 'id' => 'id', 'name' => 'name']],
                    ['type' => 'select', 'label' => $l('Konsument z UE bez VAT w sklepie'), 'name' => 'BILLTO_EU_CONSUMER_NO_VAT', 'options' => ['query' => [['id' => Settings::EU_CONSUMER_NO_VAT_BLOCK, 'name' => $l('Nie wystawiaj faktury, zapisz błąd (sklep powinien naliczyć VAT)')], ['id' => Settings::EU_CONSUMER_NO_VAT_MAP, 'name' => $l('Mapuj jak sprzedaż krajową (sprzedawca zwolniony z VAT)')]], 'id' => 'id', 'name' => 'name'], 'desc' => $l('Dotyczy trybu „stawki polskie": pozycja bez podatku dla konsumenta z UE zwykle oznacza brak reguły podatkowej dla tego kraju w sklepie.')],
                    ['type' => 'switch', 'label' => $l('Firma z UE (numer VAT UE): 0% WDT / np'), 'name' => 'BILLTO_EU_B2B_ENABLED', 'is_bool' => true, 'values' => $this->yesNo()],
                    ['type' => 'select', 'label' => $l('Weryfikacja VIES'), 'name' => 'BILLTO_VIES_CHECK', 'options' => ['query' => [['id' => Settings::VIES_BLOCK, 'name' => $l('Sprawdzaj; nieaktywny numer = nie wystawiaj faktury')], ['id' => Settings::VIES_DOMESTIC, 'name' => $l('Sprawdzaj; nieaktywny numer = stawki polskie')], ['id' => Settings::VIES_OFF, 'name' => $l('Nie sprawdzaj')]], 'id' => 'id', 'name' => 'name']],
                    ['type' => 'switch', 'label' => $l('Nabywca spoza UE: 0% eksport / np'), 'name' => 'BILLTO_NON_EU_ENABLED', 'is_bool' => true, 'values' => $this->yesNo()],
                    ['type' => 'switch', 'label' => $l('Zagraniczny nabywca z naliczonym VAT: stawki polskie'), 'name' => 'BILLTO_FOREIGN_TAXED_FOLLOWS_SHOP', 'is_bool' => true, 'values' => $this->yesNo(), 'desc' => $l('Gdy sklep naliczył polski VAT firmie z UE lub nabywcy spoza UE (brak reguły 0% dla tego kraju), faktura dostaje stawki polskie, czyli to, co klient zapłacił. Wyłączone: 0% WDT / eksport mimo pobranego VAT.')],

                    ['type' => 'select', 'label' => $l('Doręczenie faktury'), 'name' => 'BILLTO_DELIVERY_MODE', 'options' => ['query' => [['id' => Config::DELIVERY_BILLTO_EMAIL, 'name' => $l('E-mail z BillTo (PDF + strona z QR do przelewu)')], ['id' => Config::DELIVERY_LINK_ONLY, 'name' => $l('Tylko link do PDF w koncie klienta i w panelu')]], 'id' => 'id', 'name' => 'name']],
                    ['type' => 'text', 'label' => $l('Seria faktur VAT (id z BillTo)'), 'name' => 'BILLTO_SERIES_ID', 'desc' => $l('Puste = domyślna seria zespołu.') . ' ' . $this->seriesHint('VAT')],
                    ['type' => 'text', 'label' => $l('Seria faktur korygujących (id)'), 'name' => 'BILLTO_KOR_SERIES_ID', 'desc' => $this->seriesHint('KOR')],
                    ['type' => 'text', 'label' => $l('Seria faktur OSS (id)'), 'name' => 'BILLTO_OSS_SERIES_ID', 'desc' => $this->seriesHint('OSS')],

                    ['type' => 'select', 'label' => $l('Tryb pracy'), 'name' => 'BILLTO_SYNC_MODE', 'options' => ['query' => [['id' => Config::SYNC_IMMEDIATE, 'name' => $l('Od razu w trakcie zdarzenia (ponowienia przez cron)')], ['id' => Config::SYNC_CRON, 'name' => $l('Wszystko przez cron (checkout nie czeka na BillTo)')]], 'id' => 'id', 'name' => 'name']],
                ],
                'submit' => ['title' => $l('Zapisz')],
            ],
        ];

        $helper = new HelperForm();
        $helper->module = $this->module;
        $helper->name_controller = $this->module->name;
        $helper->token = Tools::getAdminTokenLite('AdminModules');
        $helper->currentIndex = \AdminController::$currentIndex . '&configure=' . $this->module->name;
        $helper->default_form_language = (int) \Configuration::get('PS_LANG_DEFAULT');
        $helper->submit_action = 'submitBilltoConfig';
        $helper->show_toolbar = false;

        $values = [];

        foreach (array_keys(Config::defaults()) as $key) {
            $name = 'BILLTO_' . $key;
            $value = $this->config->get($key);

            if (in_array($key, ['PAID_STATES', 'SETTLE_STATES'], true)) {
                $values[$name . '[]'] = $this->config->{$key === 'PAID_STATES' ? 'paidStates' : 'settleStates'}();
            } elseif ($key === 'UNPAID_MODULES') {
                $values[$name . '[]'] = $this->config->unpaidModules();
            } else {
                $values[$name] = $value;
            }
        }

        $helper->fields_value = $values;

        return $helper->generateForm([$fields]);
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function yesNo(): array
    {
        return [
            ['id' => 'active_on', 'value' => 1, 'label' => $this->module->l('Tak', 'configform')],
            ['id' => 'active_off', 'value' => 0, 'label' => $this->module->l('Nie', 'configform')],
        ];
    }

    /** Lists the team's series of a type (id + name) so the merchant can copy the id. */
    private function seriesHint(string $type): string
    {
        $client = $this->module->apiClient();

        if (!$client->isConfigured()) {
            return '';
        }

        try {
            $series = $client->get(ApiPaths::invoiceSeries(), ['type' => $type]);
        } catch (ApiException $e) {
            return '';
        }

        $parts = [];

        foreach (isset($series['data']) ? $series['data'] : [] as $row) {
            $parts[] = htmlspecialchars((isset($row['name']) ? $row['name'] : $row['code']) . (!empty($row['is_default']) ? ' *' : '')) . ': <code>' . htmlspecialchars((string) $row['id']) . '</code>';
        }

        return $parts === [] ? '' : $this->module->l('Dostępne:', 'configform') . ' ' . implode(', ', $parts);
    }
}
