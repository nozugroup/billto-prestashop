<?php
/**
 * Adres powrotny przepływu Authorization Code.
 *
 * DLACZEGO KONTROLER FRONTOWY, A NIE ADMINISTRACYJNY: adresy kontrolerów admina w PrestaShop
 * zawierają token sesji i zmieniają się między sesjami, a `redirect_uri` musi być STAŁY -
 * BillTo porównuje go przy wymianie kodu i odrzuca żądanie przy każdej różnicy. Adres frontowy
 * jest niezmienny przez cały czas życia instalacji.
 *
 * Skoro jest publiczny, ochroną nie jest sesja, tylko wartość `state` odłożona po stronie
 * serwera przy rozpoczęciu przepływu. Jest jednorazowa (kasujemy ją zaraz po odczycie), więc
 * podrzucony albo powtórzony adres nie podłączy sklepu do cudzej firmy.
 */

use BillTo\PrestaShop\Api\OAuthConnection;

class BilltoInvoicesOauthModuleFrontController extends ModuleFrontController
{
    public function initContent()
    {
        /** @var BilltoInvoices $module */
        $module = $this->module;
        $config = $module->config();

        $expectedState = (string) $config->get('OAUTH_STATE');
        $verifier = (string) $config->get('OAUTH_VERIFIER');

        // Jednorazowość: kasujemy zanim cokolwiek sprawdzimy, żeby powtórzone wywołanie tego
        // samego adresu nie miało już z czym się porównać.
        $config->set('OAUTH_STATE', '');
        $config->set('OAUTH_VERIFIER', '');

        $state = (string) Tools::getValue('state');
        $code = (string) Tools::getValue('code');

        if ($expectedState === '' || !hash_equals($expectedState, $state)) {
            $this->finish('state_mismatch');
        }

        if ($code === '') {
            // Sklepikarz kliknął „Odmawiam" albo BillTo odrzuciło żądanie - to nie jest awaria.
            $this->finish('denied');
        }

        $connection = $module->oauthConnection();

        $tokens = $connection->exchange($code, $verifier, $module->oauthRedirectUri());

        $this->finish($tokens ? 'connected' : 'exchange_failed');
    }

    /**
     * Wraca do konfiguracji modułu z wynikiem. Adres budujemy przez Link, bo kontroler
     * administracyjny wymaga aktualnego tokenu sesji.
     */
    private function finish(string $result)
    {
        $url = $this->context->link->getAdminLink('AdminModules', true)
            . '&configure=billtoinvoices&billto_oauth=' . urlencode($result);

        Tools::redirectAdmin($url);
        exit;
    }
}
