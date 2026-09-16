<?php
/**
 * Customer download of the invoice PDF: the customer must own the order (or know its secure key).
 */

use BillTo\PrestaShop\Support\OrderRecord;

class BilltoInvoicesPdfModuleFrontController extends ModuleFrontController
{
    public function initContent()
    {
        $idOrder = (int) Tools::getValue('id_order');
        $key = (string) Tools::getValue('key');
        $order = new Order($idOrder);

        if (!$order->id) {
            Tools::redirect('index.php?controller=404');
        }

        $customerId = $this->context->customer->isLogged() ? (int) $this->context->customer->id : 0;
        $allowed = ($customerId > 0 && (int) $order->id_customer === $customerId) || ($key !== '' && hash_equals((string) $order->secure_key, $key));

        if (!$allowed) {
            Tools::redirect('index.php?controller=authentication&back=my-account');
        }

        $record = OrderRecord::find($idOrder);
        /** @var BilltoInvoices $module */
        $module = $this->module;

        if ($record === null || $record->invoiceId === '') {
            Tools::redirect('index.php?controller=404');
        }

        if (!is_file($record->pdfPath)) {
            $module->orderSync()->fetchPdf($record);
        }

        if (!is_file($record->pdfPath)) {
            Tools::redirect('index.php?controller=404');
        }

        header('Content-Type: application/pdf');
        header('Content-Disposition: inline; filename="' . preg_replace('/[^A-Za-z0-9._-]/', '-', $record->invoiceNumber !== '' ? $record->invoiceNumber : 'faktura-' . $idOrder) . '.pdf"');
        header('Content-Length: ' . filesize($record->pdfPath));
        readfile($record->pdfPath);
        exit;
    }
}
