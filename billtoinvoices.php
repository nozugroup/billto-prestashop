<?php
/**
 * BillTo for PrestaShop - VAT invoices and KSeF for PrestaShop orders through BillTo.
 *
 * @author    BillTo
 * @copyright 2026 NOZU sp. z o.o.
 * @license   https://opensource.org/licenses/AFL-3.0 Academic Free License 3.0
 */

if (!defined('_PS_VERSION_')) {
    exit;
}

require_once __DIR__ . '/autoload.php';

use BillTo\PrestaShop\Api\Client;
use BillTo\PrestaShop\Install\Installer;
use BillTo\PrestaShop\Support\Config;
use BillTo\PrestaShop\Support\Logger;
use BillTo\PrestaShop\Support\OrderRecord;
use BillTo\PrestaShop\Support\PdfStorage;
use BillTo\PrestaShop\Sync\OrderAdapter;
use BillTo\PrestaShop\Sync\OrderSync;
use BillTo\PrestaShop\Sync\Queue;
use BillTo\PrestaShop\Sync\RefundSync;
use BillTo\PrestaShop\Admin\ConfigForm;

class BilltoInvoices extends Module
{
    /** @var Config */
    private $config;

    /** @var OrderSync|null */
    private $orderSync;

    /** @var RefundSync|null */
    private $refundSync;

    public function __construct()
    {
        $this->name = 'billtoinvoices';
        $this->tab = 'billing_invoicing';
        $this->version = '0.1.0';
        $this->author = 'BillTo';
        $this->need_instance = 0;
        $this->ps_versions_compliancy = ['min' => '1.7.6.0', 'max' => _PS_VERSION_];
        $this->bootstrap = true;

        parent::__construct();

        $this->displayName = $this->l('BillTo - faktury i KSeF');
        $this->description = $this->l('Wystawia faktury VAT w BillTo dla zamówień PrestaShop: zamówienie trafia do BillTo, po opłaceniu powstaje faktura, opcjonalnie wysyłana do KSeF. Korekty przy zwrotach, PDF w panelu i w koncie klienta.');
        $this->confirmUninstall = $this->l('Odinstalować moduł? Dane powiązań zamówień z fakturami zostaną usunięte z bazy sklepu (faktury w BillTo pozostają).');

        $this->config = new Config();
    }

    public function install()
    {
        return parent::install()
            && (new Installer($this))->install()
            && $this->registerHook('actionValidateOrder')
            && $this->registerHook('actionOrderStatusPostUpdate')
            && $this->registerHook('actionOrderSlipAdd')
            && $this->registerHook('displayAdminOrderSide')
            && $this->registerHook('displayOrderDetail')
            && $this->registerHook('actionObjectOrderUpdateAfter');
    }

    public function uninstall()
    {
        return (new Installer($this))->uninstall() && parent::uninstall();
    }

    public function getContent()
    {
        return (new ConfigForm($this, $this->config))->render();
    }

    // ---- Hooks -------------------------------------------------------------------------------

    /** New order placed (any payment module). */
    public function hookActionValidateOrder(array $params)
    {
        if (!isset($params['order']) || !$params['order'] instanceof Order) {
            return;
        }

        $this->guarded(function () use ($params) {
            $this->orderSync()->onOrderPlaced((int) $params['order']->id);
        });
    }

    /** Order status changed (payment accepted, shipped, cancelled...). */
    public function hookActionOrderStatusPostUpdate(array $params)
    {
        if (!isset($params['newOrderStatus'], $params['id_order'])) {
            return;
        }

        $status = $params['newOrderStatus'];

        $this->guarded(function () use ($params, $status) {
            $this->orderSync()->onStatusChanged((int) $params['id_order'], (int) $status->id);
        });
    }

    /** Credit slip (refund) created in the back office. */
    public function hookActionOrderSlipAdd(array $params)
    {
        if (!isset($params['order']) || !$params['order'] instanceof Order) {
            return;
        }

        $productList = isset($params['productList']) && is_array($params['productList']) ? $params['productList'] : [];
        $qtyList = isset($params['qtyList']) && is_array($params['qtyList']) ? $params['qtyList'] : [];

        $this->guarded(function () use ($params, $productList, $qtyList) {
            $this->refundSync()->onSlipAdded($params['order'], $productList, $qtyList);
        });
    }

    /** Order edited in the back office before invoicing (address, products). */
    public function hookActionObjectOrderUpdateAfter(array $params)
    {
        if (!isset($params['object']) || !$params['object'] instanceof Order) {
            return;
        }

        $this->guarded(function () use ($params) {
            $this->orderSync()->onOrderEdited((int) $params['object']->id);
        });
    }

    /** BillTo box on the order page (PrestaShop 1.7.7+). */
    public function hookDisplayAdminOrderSide(array $params)
    {
        $idOrder = isset($params['id_order']) ? (int) $params['id_order'] : 0;

        if ($idOrder === 0) {
            return '';
        }

        $record = OrderRecord::find($idOrder);
        $link = $this->context->link->getAdminLink('AdminBilltoInvoices');

        $this->context->smarty->assign([
            'billto_record' => $record ? $record->toArray() : null,
            'billto_action_url' => $link,
            'billto_id_order' => $idOrder,
            'billto_sandbox' => $this->config->isSandbox(),
            'billto_scenario_label' => $record ? $this->orderSync()->scenarioLabel($idOrder) : '',
            'billto_warnings' => $record ? $this->orderSync()->warningLabels($idOrder) : [],
        ]);

        return $this->display(__FILE__, 'views/templates/hook/admin_order_side.tpl');
    }

    /** Invoice link on the customer's order detail page. */
    public function hookDisplayOrderDetail(array $params)
    {
        if (!isset($params['order']) || !$params['order'] instanceof Order) {
            return '';
        }

        $record = OrderRecord::find((int) $params['order']->id);

        if ($record === null || $record->invoiceId === '') {
            return '';
        }

        $this->context->smarty->assign([
            'billto_invoice_number' => $record->invoiceNumber,
            'billto_public_url' => $record->publicUrl,
            'billto_pdf_url' => $this->context->link->getModuleLink($this->name, 'pdf', ['id_order' => (int) $params['order']->id, 'key' => $params['order']->secure_key]),
        ]);

        return $this->display(__FILE__, 'views/templates/hook/order_detail.tpl');
    }

    // ---- Services ----------------------------------------------------------------------------

    public function orderSync(): OrderSync
    {
        if ($this->orderSync === null) {
            $this->orderSync = new OrderSync($this->config, new Logger(), new OrderAdapter($this->config), new PdfStorage(__DIR__ . '/pdf'), $this->clientFactory(), $this);
        }

        return $this->orderSync;
    }

    public function refundSync(): RefundSync
    {
        if ($this->refundSync === null) {
            $this->refundSync = new RefundSync($this->config, new Logger(), new PdfStorage(__DIR__ . '/pdf'), $this->clientFactory(), $this);
        }

        return $this->refundSync;
    }

    public function queue(): Queue
    {
        return new Queue($this->config, $this->orderSync(), $this->refundSync(), new Logger());
    }

    public function config(): Config
    {
        return $this->config;
    }

    /** @return callable(): Client */
    private function clientFactory(): callable
    {
        $config = $this->config;

        return static function () use ($config) {
            return new Client($config->token(), $config->baseUrl(), new Logger());
        };
    }

    /**
     * Hooks must never break the checkout: everything is caught and logged.
     */
    private function guarded(callable $work)
    {
        try {
            $work();
        } catch (\Throwable $e) {
            (new Logger())->error('Hook failed: ' . $e->getMessage());
        }
    }
}
