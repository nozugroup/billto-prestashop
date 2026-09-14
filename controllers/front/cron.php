<?php
/**
 * Cron endpoint: runs due background jobs. Protected by the token from the module configuration.
 *
 * Example: * /5 * * * * curl -s "https://shop.example/module/billtoinvoices/cron?token=..."
 */

class BilltoInvoicesCronModuleFrontController extends ModuleFrontController
{
    public function initContent()
    {
        /** @var BilltoInvoices $module */
        $module = $this->module;
        $token = (string) Tools::getValue('token');

        if ($token === '' || !hash_equals($module->config()->cronToken(), $token)) {
            header('HTTP/1.1 403 Forbidden');
            exit('Forbidden');
        }

        $processed = $module->queue()->runDue(50);

        header('Content-Type: application/json');
        exit(json_encode(['processed' => $processed]));
    }
}
