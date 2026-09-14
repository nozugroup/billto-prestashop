<?php
/**
 * Manual actions from the order page and the admin PDF download.
 */

use BillTo\PrestaShop\Support\OrderRecord;
use BillTo\PrestaShop\Sync\RetryableFailure;

class AdminBilltoInvoicesController extends ModuleAdminController
{
    public function __construct()
    {
        $this->bootstrap = true;
        parent::__construct();
    }

    public function postProcess()
    {
        $idOrder = (int) Tools::getValue('id_order');
        $action = (string) Tools::getValue('do');

        if ($idOrder === 0 || !$this->module instanceof BilltoInvoices) {
            $this->errors[] = $this->l('Brak zamówienia.');

            return;
        }

        /** @var BilltoInvoices $module */
        $module = $this->module;
        $sync = $module->orderSync();

        if ($action === 'pdf') {
            $this->servePdf($idOrder);

            return;
        }

        $message = $this->l('Nieznana akcja.');

        try {
            switch ($action) {
                case 'sync':
                    $message = $sync->createOrUpdateBillToOrder($idOrder) !== null ? $this->l('Zamówienie zsynchronizowane z BillTo.') : $this->lastError($idOrder);
                    break;
                case 'invoice':
                    $sync->invoiceOrder($idOrder, true);
                    $record = OrderRecord::find($idOrder);
                    $message = $record !== null && $record->invoiceId !== '' ? $this->l('Faktura wystawiona.') : $this->lastError($idOrder);
                    break;
                case 'ksef':
                    $record = OrderRecord::find($idOrder);
                    $message = $record !== null && $sync->sendToKsef($record) !== null ? $this->l('Faktura przekazana do KSeF.') : $this->lastError($idOrder);
                    break;
                case 'ksef-status':
                    $record = OrderRecord::find($idOrder);
                    $message = $record !== null && $sync->refreshKsefStatus($record) !== null ? $this->l('Status KSeF odświeżony.') : $this->l('Nie udało się pobrać statusu KSeF.');
                    break;
                case 'settle':
                    $sync->settleInvoice($idOrder);
                    $record = OrderRecord::find($idOrder);
                    $message = $record !== null && $record->invoicePaid === true ? $this->l('Faktura oznaczona jako zapłacona.') : $this->lastError($idOrder);
                    break;
                case 'send-email':
                    $sentTo = $sync->sendEmail($idOrder);
                    $message = $sentTo !== null ? sprintf($this->l('Faktura wysłana na %s.'), $sentTo) : $this->lastError($idOrder);
                    break;
                case 'refresh-pdf':
                    $record = OrderRecord::find($idOrder);
                    $message = $record !== null && $sync->fetchPdf($record) !== null ? $this->l('PDF pobrany ponownie.') : $this->l('Nie udało się pobrać PDF.');
                    break;
            }
        } catch (RetryableFailure $e) {
            $message = sprintf($this->l('BillTo chwilowo niedostępne: %s. Operacja zostanie powtórzona przez cron.'), $e->getMessage());
        }

        Tools::redirectAdmin($this->context->link->getAdminLink('AdminOrders', true, ['id_order' => $idOrder, 'vieworder' => 1]) . '&conf=4&billto_notice=' . rawurlencode($message));
    }

    private function servePdf(int $idOrder): void
    {
        $record = OrderRecord::find($idOrder);
        /** @var BilltoInvoices $module */
        $module = $this->module;

        if ($record === null || $record->invoiceId === '') {
            die($this->l('Brak faktury.'));
        }

        if (!is_file($record->pdfPath)) {
            $module->orderSync()->fetchPdf($record);
        }

        if (!is_file($record->pdfPath)) {
            die($this->l('PDF niedostępny.'));
        }

        header('Content-Type: application/pdf');
        header('Content-Disposition: inline; filename="' . preg_replace('/[^A-Za-z0-9._-]/', '-', $record->invoiceNumber !== '' ? $record->invoiceNumber : 'faktura-' . $idOrder) . '.pdf"');
        header('Content-Length: ' . filesize($record->pdfPath));
        readfile($record->pdfPath);
        exit;
    }

    private function lastError(int $idOrder): string
    {
        $record = OrderRecord::find($idOrder);

        return $record !== null && $record->lastError !== '' ? $record->lastError : $this->l('Operacja nie powiodła się - sprawdź logi (Zaawansowane -> Logi).');
    }
}
