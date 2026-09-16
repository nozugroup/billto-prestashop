<?php
/**
 * Redirect URI of the Authorization Code flow.
 *
 * A front controller, because `redirect_uri` has to be constant and BillTo compares it during
 * the code exchange, while PrestaShop admin URLs carry a per-session token.
 *
 * The route is public, so the callback is guarded by the single-use `state` value stored on the
 * server when the flow started, not by a session.
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

        // Cleared before any check, so a repeated call has nothing left to compare against.
        $config->set('OAUTH_STATE', '');
        $config->set('OAUTH_VERIFIER', '');

        $state = (string) Tools::getValue('state');
        $code = (string) Tools::getValue('code');

        if ($expectedState === '' || !hash_equals($expectedState, $state)) {
            $this->finish('state_mismatch');
        }

        if ($code === '') {
            // The merchant denied the request, or BillTo rejected it; not a failure.
            $this->finish('denied');
        }

        $connection = $module->oauthConnection();

        $tokens = $connection->exchange($code, $verifier, $module->oauthRedirectUri());

        $this->finish($tokens ? 'connected' : 'exchange_failed');
    }

    /**
     * Returns to the module configuration with the result.
     *
     * The return URL is stored by ConfigForm when the flow starts: admin controller URLs need an
     * employee session token, which this front controller does not have.
     */
    private function finish(string $result)
    {
        $config = $this->module->config();

        $return = (string) $config->get('OAUTH_RETURN');
        $config->set('OAUTH_RETURN', '');

        if ($return === '') {
            // With no stored URL, fall back to the shop front page.
            $return = $this->context->link->getPageLink('index', true);
        }

        $url = $return . (strpos($return, '?') === false ? '?' : '&')
            . 'billto_oauth=' . urlencode($result);

        Tools::redirect($url);
        exit;
    }
}
