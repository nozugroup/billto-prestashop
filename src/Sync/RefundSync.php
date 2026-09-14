<?php

namespace BillTo\PrestaShop\Sync;

use BillTo\PrestaShop\Api\ApiException;
use BillTo\PrestaShop\Api\Client;
use BillTo\PrestaShop\Support\Config;
use BillTo\PrestaShop\Support\Logger;
use BillTo\PrestaShop\Support\OrderRecord;
use BillTo\PrestaShop\Support\PdfStorage;
use BillTo\Shop\ApiPaths;
use BillTo\Shop\MarkPaidPayload;
use BillTo\Shop\RefundLines;
use BillTo\Shop\Settings;
use Db;
use DbQuery;
use Order;
use OrderSlip;

/**
 * PrestaShop credit slip -> BillTo correcting order + KOR. Product quantities on the slip give
 * the remaining quantities; a slip without products (amount-only) becomes a proportional
 * price reduction or is left for a manual correction, per settings.
 */
final class RefundSync
{
    /** @var Config */
    private $config;

    /** @var Logger */
    private $logger;

    /** @var PdfStorage */
    private $pdf;

    /** @var callable(): Client */
    private $client;

    /** @var \Module */
    private $module;

    public function __construct(Config $config, Logger $logger, PdfStorage $pdf, callable $client, \Module $module)
    {
        $this->config = $config;
        $this->logger = $logger;
        $this->pdf = $pdf;
        $this->client = $client;
        $this->module = $module;
    }

    /**
     * @param array<int, array<string, mixed>> $productList
     * @param array<int, int> $qtyList
     */
    public function onSlipAdded(Order $order, array $productList, array $qtyList): void
    {
        if (!$this->config->refundCorrections()) {
            return;
        }

        $record = OrderRecord::find((int) $order->id);

        if ($record === null || $record->invoiceId === '') {
            return;
        }

        // The slip was just inserted; take the newest one for this order.
        $query = new DbQuery();
        $query->select('id_order_slip')->from('order_slip')->where('id_order = ' . (int) $order->id)->orderBy('id_order_slip DESC');
        $idSlip = (int) Db::getInstance()->getValue($query);

        if ($idSlip > 0) {
            Queue::dispatch($this->config, Queue::JOB_REFUND, (int) $order->id, 0, $this->module, ['id_order_slip' => $idSlip]);
        }
    }

    /**
     * @throws RetryableFailure
     */
    public function correctForSlip(int $idOrder, int $idSlip): void
    {
        $record = OrderRecord::find($idOrder);

        if ($record === null || $record->billtoOrderId === '' || $idSlip === 0 || isset($record->corrections[(string) $idSlip])) {
            return;
        }

        $order = new Order($idOrder);
        $slip = new OrderSlip($idSlip);

        if (!$order->id || !$slip->id) {
            return;
        }

        $lines = $this->linesForSlip($order, $slip, $record->lineMap);

        if ($lines === [] && $this->config->toSettings()->amountRefundMode === Settings::REFUND_PROPORTIONAL) {
            $lines = $this->proportionalLines($order, $record->lineMap, (float) $slip->amount + (float) $slip->shipping_cost_amount);
        }

        if ($lines === []) {
            $record->rememberError(sprintf($this->module->l('Dowód zwrotu #%d nie ma pozycji - wystaw fakturę korygującą ręcznie w BillTo.', 'refundsync'), $idSlip));

            return;
        }

        $client = call_user_func($this->client);

        try {
            $step1 = $client->post(ApiPaths::issueCorrection($record->billtoOrderId), [
                'lines' => $lines,
                'reason' => sprintf($this->module->l('Zwrot PrestaShop #%d', 'refundsync'), $idSlip),
            ], Client::operationKey('order-' . $idOrder . '-slip-' . $idSlip . '-correction'));

            $correctingId = isset($step1['data']['id']) ? (string) $step1['data']['id'] : '';

            if ($correctingId === '') {
                throw new ApiException('Missing correcting order id', 500);
            }

            $step2 = $client->post(ApiPaths::issueKor($correctingId), MarkPaidPayload::issueKor($this->config->toSettings()), Client::operationKey('order-' . $idOrder . '-slip-' . $idSlip . '-kor'));
        } catch (ApiException $e) {
            if ($e->isRetryable()) {
                $record->rememberError($e->getMessage());

                throw new RetryableFailure($e->getMessage(), 0, $e);
            }

            $record->rememberError(sprintf($this->module->l('Korekta dla zwrotu #%d nie powiodła się: ', 'refundsync'), $idSlip) . $e->getMessage());
            $this->logger->error(sprintf('Order #%d slip #%d correction failed: %s', $idOrder, $idSlip, $e->getMessage()));

            return;
        }

        $kor = isset($step2['data']) && is_array($step2['data']) ? $step2['data'] : [];
        $record->corrections[(string) $idSlip] = [
            'invoice_id' => isset($kor['id']) ? $kor['id'] : null,
            'invoice_number' => isset($kor['invoice_number']) ? $kor['invoice_number'] : (isset($kor['id']) ? $kor['id'] : ''),
        ];
        $record->lastError = '';
        $record->save();

        if (!empty($kor['id'])) {
            try {
                $content = $client->download(ApiPaths::invoicePdf((string) $kor['id']));

                if (strpos($content, '%PDF') === 0) {
                    $this->pdf->store($idOrder, 'kor-' . $idSlip, $content);
                }
            } catch (ApiException $e) {
                $this->logger->warning('KOR PDF download failed: ' . $e->getMessage());
            }
        }
    }

    /**
     * Remaining quantities per invoiced line, cumulative across all slips of the order.
     *
     * @param array<string, int> $lineMap
     * @return array<int, array<string, mixed>>
     */
    public function linesForSlip(Order $order, OrderSlip $slip, array $lineMap): array
    {
        $refunded = [];

        foreach (OrderSlip::getOrdersSlipProducts((int) $slip->id, $order) as $row) {
            $detailId = (string) $row['id_order_detail'];
            $refundedTotal = $this->totalRefundedQuantity($order, (int) $row['id_order_detail']);

            $refunded[] = [
                'id' => $detailId,
                'originalQuantity' => (float) $row['product_quantity'],
                'refundedQuantity' => (float) $refundedTotal,
                'isProduct' => true,
            ];
        }

        // Shipping refunded on the slip.
        if ((float) $slip->shipping_cost_amount > 0 && isset($lineMap['shipping'])) {
            $refunded[] = ['id' => 'shipping', 'originalQuantity' => 1.0, 'refundedQuantity' => 1.0, 'isProduct' => false];
        }

        return RefundLines::remaining($lineMap, $refunded);
    }

    /**
     * @param array<string, int> $lineMap
     * @return array<int, array<string, mixed>>
     */
    public function proportionalLines(Order $order, array $lineMap, float $refundAmount): array
    {
        $lines = [];

        foreach ($order->getProducts() as $row) {
            $lines[] = ['id' => (string) $row['id_order_detail'], 'quantity' => (float) $row['product_quantity'], 'netTotal' => (float) $row['total_price_tax_excl']];
        }

        if (isset($lineMap['shipping'])) {
            $lines[] = ['id' => 'shipping', 'quantity' => 1.0, 'netTotal' => (float) $order->total_shipping_tax_excl];
        }

        return RefundLines::proportional($lineMap, $lines, $refundAmount, (float) $order->total_paid_tax_incl);
    }

    /** Quantity refunded so far for an order detail across all slips (the slip products table). */
    private function totalRefundedQuantity(Order $order, int $idOrderDetail): int
    {
        $query = new DbQuery();
        $query->select('SUM(sd.product_quantity)')
            ->from('order_slip_detail', 'sd')
            ->innerJoin('order_slip', 's', 's.id_order_slip = sd.id_order_slip')
            ->where('s.id_order = ' . (int) $order->id)
            ->where('sd.id_order_detail = ' . (int) $idOrderDetail);

        return (int) Db::getInstance()->getValue($query);
    }
}
