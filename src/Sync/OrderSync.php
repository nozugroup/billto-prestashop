<?php

namespace BillTo\PrestaShop\Sync;

use BillTo\PrestaShop\Api\ApiException;
use BillTo\PrestaShop\Api\Client;
use BillTo\PrestaShop\Support\Config;
use BillTo\PrestaShop\Support\CurlHttp;
use BillTo\PrestaShop\Support\Logger;
use BillTo\PrestaShop\Support\OrderRecord;
use BillTo\PrestaShop\Support\PdfStorage;
use BillTo\Shop\ApiPaths;
use BillTo\Shop\BuyerScenario;
use BillTo\Shop\KsefStatus;
use BillTo\Shop\MarkPaidPayload;
use BillTo\Shop\OrderPayloadBuilder;
use BillTo\Shop\Settings;
use BillTo\Shop\Vies\Vies;
use Order;
use OrderState;

/**
 * PrestaShop order lifecycle -> BillTo: create/update the order, invoice it when paid (unpaid for
 * deferred payment modules, settled later), cancel it, fetch the PDF, submit to KSeF.
 *
 * In "immediate" sync mode the API is called inside the hook (wrapped so it never breaks the
 * checkout); in "cron" mode the work is queued and run by the cron controller.
 */
final class OrderSync
{
    /** @var Config */
    private $config;

    /** @var Logger */
    private $logger;

    /** @var OrderAdapter */
    private $adapter;

    /** @var PdfStorage */
    private $pdf;

    /** @var callable(): Client */
    private $client;

    /** @var \Module */
    private $module;

    public function __construct(Config $config, Logger $logger, OrderAdapter $adapter, PdfStorage $pdf, callable $client, \Module $module)
    {
        $this->config = $config;
        $this->logger = $logger;
        $this->adapter = $adapter;
        $this->pdf = $pdf;
        $this->client = $client;
        $this->module = $module;
    }

    // ---- Hook entry points ---------------------------------------------------------------------

    public function onOrderPlaced(int $idOrder): void
    {
        $order = new Order($idOrder);

        if (!$this->shouldHandle($order)) {
            return;
        }

        if ($this->config->createOnCheckout()) {
            $this->dispatch(Queue::JOB_SYNC, $idOrder);
        }

        // Payment modules that validate the order directly into a paid state.
        if ($this->isPaidState((int) $order->current_state)) {
            $this->dispatch(Queue::JOB_INVOICE, $idOrder);
        }
    }

    public function onStatusChanged(int $idOrder, int $idState): void
    {
        $order = new Order($idOrder);

        if (!$this->shouldHandle($order)) {
            return;
        }

        if ($this->isPaidState($idState)) {
            $this->dispatch(Queue::JOB_INVOICE, $idOrder);
        }

        $record = OrderRecord::find($idOrder);

        if ($record !== null && $record->invoiceId !== '' && $record->invoicePaid === false && $this->isSettleState($idState)) {
            $this->dispatch(Queue::JOB_SETTLE, $idOrder);
        }

        if ($idState === (int) \Configuration::get('PS_OS_CANCELED') && $record !== null && $record->billtoOrderId !== '') {
            $this->dispatch(Queue::JOB_CANCEL, $idOrder);
        }
    }

    public function onOrderEdited(int $idOrder): void
    {
        if (!$this->config->resyncOnEdit()) {
            return;
        }

        $record = OrderRecord::find($idOrder);

        if ($record !== null && $record->billtoOrderId !== '' && $record->invoiceId === '') {
            $this->dispatch(Queue::JOB_SYNC, $idOrder);
        }
    }

    // ---- Decisions -----------------------------------------------------------------------------

    public function shouldHandle(Order $order): bool
    {
        if (!$this->clientFor()->isConfigured() || !$order->id) {
            return false;
        }

        $buyer = $this->adapter->buyer($order);

        if ($this->config->invoicesOnlyWithVatNumber() && !$buyer->hasTaxId()) {
            return false;
        }

        return BuyerScenario::isEnabled(BuyerScenario::forBuyer($buyer), $this->config->toSettings());
    }

    public function isPaidState(int $idState): bool
    {
        $configured = $this->config->paidStates();

        if ($configured !== []) {
            return in_array($idState, $configured, true);
        }

        $state = new OrderState($idState);

        return (bool) $state->paid;
    }

    public function isSettleState(int $idState): bool
    {
        $configured = $this->config->settleStates();

        if ($configured !== []) {
            return in_array($idState, $configured, true);
        }

        $state = new OrderState($idState);

        return (bool) $state->delivery || (bool) $state->shipped;
    }

    public function scenarioLabel(int $idOrder): string
    {
        $record = OrderRecord::find($idOrder);
        $scenario = $record !== null && $record->scenario !== '' ? $record->scenario : BuyerScenario::forBuyer($this->adapter->buyer(new Order($idOrder)));

        switch ($scenario) {
            case BuyerScenario::PL_B2C:
                return $this->module->l('osoba fizyczna (PL)', 'ordersync');
            case BuyerScenario::PL_B2B:
                return $this->module->l('firma (PL)', 'ordersync');
            case BuyerScenario::EU_B2C:
                return $this->module->l('konsument z UE', 'ordersync');
            case BuyerScenario::EU_B2B:
                return $this->module->l('firma z UE (WDT / np)', 'ordersync');
            case BuyerScenario::EU_B2B_DOMESTIC:
                return $this->module->l('firma z UE, VAT nieaktywny (stawki polskie)', 'ordersync');
            case BuyerScenario::NON_EU:
                return $this->module->l('nabywca spoza UE (eksport / np)', 'ordersync');
            default:
                return $scenario;
        }
    }

    // ---- Work ----------------------------------------------------------------------------------

    /**
     * @throws RetryableFailure
     */
    public function createOrUpdateBillToOrder(int $idOrder): ?string
    {
        $order = new Order($idOrder);

        if (!$order->id) {
            return null;
        }

        $record = OrderRecord::findOrNew($idOrder);

        if ($record->invoiceId !== '') {
            return $record->billtoOrderId; // invoiced orders are frozen in BillTo
        }

        if (!$this->viesAllows($order, $record)) {
            return null;
        }

        $viesStatus = $record->vies !== null && isset($record->vies['status']) ? (string) $record->vies['status'] : null;
        $built = (new OrderPayloadBuilder($this->config->toSettings()))->build($this->adapter->toCoreOrder($order), $viesStatus);

        if ($built['hasNegativeLines'] && $this->config->toSettings()->negativeLines === Settings::NEGATIVE_SKIP) {
            $record->rememberError($this->module->l('Zamówienie zawiera pozycję ujemną (rabat) - wystaw fakturę ręcznie albo włącz rozdzielanie rabatu w konfiguracji modułu.', 'ordersync'));

            return null;
        }

        $client = $this->clientFor();
        $payload = $built['payload'];

        try {
            if ($record->billtoOrderId !== '') {
                unset($payload['status'], $payload['send_confirmation']);

                try {
                    $response = $client->put(ApiPaths::order($record->billtoOrderId), $payload, Client::operationKey('order-' . $idOrder . '-update-' . md5(json_encode($payload))));
                } catch (ApiException $e) {
                    if ($e->isConflict()) {
                        return $record->billtoOrderId; // already invoiced / closed
                    }

                    throw $e;
                }
            } else {
                $response = $client->post(ApiPaths::orders(), $payload, Client::operationKey('order-' . $idOrder . '-create'));
            }
        } catch (ApiException $e) {
            $this->handleFailure($record, $e, $this->module->l('Nie udało się utworzyć zamówienia w BillTo', 'ordersync'));

            return null;
        }

        $data = isset($response['data']) && is_array($response['data']) ? $response['data'] : [];

        if (empty($data['id'])) {
            $record->rememberError($this->module->l('Odpowiedź BillTo bez identyfikatora zamówienia.', 'ordersync'));

            return null;
        }

        $record->billtoOrderId = (string) $data['id'];
        $record->billtoOrderNumber = (string) (isset($data['order_number']) ? $data['order_number'] : '');
        $record->lineMap = $built['lineMap'];
        $record->scenario = $built['scenario'];
        $record->lastError = '';
        $record->save();

        $this->logger->info(sprintf('Order #%d synced to BillTo order %s', $idOrder, $record->billtoOrderId));

        return $record->billtoOrderId;
    }

    /**
     * @throws RetryableFailure
     */
    public function invoiceOrder(int $idOrder, bool $force = false): void
    {
        $order = new Order($idOrder);

        if (!$order->id) {
            return;
        }

        $record = OrderRecord::findOrNew($idOrder);

        if ($record->invoiceId !== '' && !$force) {
            return;
        }

        if (!$force && !$this->shouldHandle($order)) {
            return;
        }

        $billtoId = $record->billtoOrderId !== '' ? $record->billtoOrderId : $this->createOrUpdateBillToOrder($idOrder);

        if ($billtoId === null || $billtoId === '') {
            return;
        }

        $record = OrderRecord::findOrNew($idOrder);
        $payload = MarkPaidPayload::build($this->adapter->toCoreOrder($order), $this->config->toSettings());

        try {
            $response = $this->clientFor()->post(ApiPaths::markPaid($billtoId), $payload, Client::operationKey('order-' . $idOrder . '-mark-paid'));
        } catch (ApiException $e) {
            if ($e->isConflict()) {
                $this->recoverInvoice($record, $billtoId);

                return;
            }

            $this->handleFailure($record, $e, $this->module->l('Nie udało się wystawić faktury w BillTo', 'ordersync'));

            return;
        }

        $this->storeInvoice($record, isset($response['data']) && is_array($response['data']) ? $response['data'] : []);
    }

    /**
     * @param array<string, mixed> $invoice
     */
    public function storeInvoice(OrderRecord $record, array $invoice): void
    {
        if (empty($invoice['id'])) {
            return;
        }

        $record->invoiceId = (string) $invoice['id'];
        $record->invoiceNumber = (string) (isset($invoice['invoice_number']) ? $invoice['invoice_number'] : '');
        $record->invoicePaid = !empty($invoice['paid_at']);
        $record->publicUrl = (string) (isset($invoice['public_url']) ? $invoice['public_url'] : '');
        $record->lastError = '';
        $record->save();

        $this->fetchPdf($record);

        if ($this->config->ksefAuto() && (isset($invoice['type']) ? $invoice['type'] : 'VAT') !== 'OSS') {
            $this->sendToKsef($record);
        }

        $order = new Order($record->idOrder);

        if ($record->invoicePaid === false && $this->isSettleState((int) $order->current_state)) {
            $this->settleInvoice($record->idOrder);
        }
    }

    /**
     * @throws RetryableFailure
     */
    public function settleInvoice(int $idOrder): void
    {
        $record = OrderRecord::find($idOrder);

        if ($record === null || $record->invoiceId === '' || $record->invoicePaid !== false) {
            return;
        }

        try {
            $this->clientFor()->post(ApiPaths::invoiceMarkPaid($record->invoiceId), ['paid_at' => date('Y-m-d')], Client::operationKey('order-' . $idOrder . '-settle'));
        } catch (ApiException $e) {
            if ($e->isValidation()) {
                $record->rememberError($this->module->l('Wpłata nie została dopisana: ', 'ordersync') . $e->getMessage());

                return;
            }

            $this->handleFailure($record, $e, $this->module->l('Nie udało się dopisać wpłaty do faktury', 'ordersync'));

            return;
        }

        $record->invoicePaid = true;
        $record->save();
    }

    public function fetchPdf(OrderRecord $record): ?string
    {
        if ($record->invoiceId === '') {
            return null;
        }

        try {
            $content = $this->clientFor()->download(ApiPaths::invoicePdf($record->invoiceId));
        } catch (ApiException $e) {
            $this->logger->warning('PDF download failed: ' . $e->getMessage());

            return null;
        }

        if (strpos($content, '%PDF') !== 0) {
            return null;
        }

        $record->pdfPath = $this->pdf->store($record->idOrder, 'invoice', $content);
        $record->save();

        return $record->pdfPath;
    }

    /**
     * @return array<string, mixed>|null
     */
    public function sendToKsef(OrderRecord $record): ?array
    {
        if ($record->invoiceId === '') {
            return null;
        }

        try {
            $response = $this->clientFor()->post(ApiPaths::invoiceKsef($record->invoiceId), null, Client::operationKey('invoice-' . $record->invoiceId . '-ksef'));
        } catch (ApiException $e) {
            $data = isset($e->body()['data']) && is_array($e->body()['data']) ? $e->body()['data'] : null;

            if ($data !== null) {
                $this->storeKsef($record, $data);
            }

            $record->rememberError('KSeF: ' . $e->getMessage());

            return null;
        }

        $data = isset($response['data']) && is_array($response['data']) ? $response['data'] : [];
        $this->storeKsef($record, $data);

        if (KsefStatus::of($data) === KsefStatus::PENDING) {
            $this->dispatch(Queue::JOB_KSEF_STATUS, $record->idOrder, 60);
        }

        return $data;
    }

    /**
     * @return array<string, mixed>|null
     */
    public function refreshKsefStatus(OrderRecord $record): ?array
    {
        if ($record->invoiceId === '') {
            return null;
        }

        try {
            $response = $this->clientFor()->get(ApiPaths::invoiceKsef($record->invoiceId));
        } catch (ApiException $e) {
            $this->logger->warning('KSeF status failed: ' . $e->getMessage());

            return null;
        }

        $data = isset($response['data']) && is_array($response['data']) ? $response['data'] : [];
        $this->storeKsef($record, $data);

        return $data;
    }

    /**
     * @param array<string, mixed> $data
     */
    private function storeKsef(OrderRecord $record, array $data): void
    {
        $record->ksefStatus = KsefStatus::of($data);
        $record->ksefNumber = KsefStatus::number($data);
        $record->save();
    }

    public function cancelBillToOrder(int $idOrder): void
    {
        $record = OrderRecord::find($idOrder);

        if ($record === null || $record->billtoOrderId === '') {
            return;
        }

        if ($record->invoiceId !== '') {
            $record->rememberError($this->module->l('Zamówienie ma fakturę - anulowanie wymaga faktury korygującej (dowód zwrotu).', 'ordersync'));

            return;
        }

        try {
            $this->clientFor()->post(ApiPaths::cancel($record->billtoOrderId), null, Client::operationKey('order-' . $idOrder . '-cancel'));
        } catch (ApiException $e) {
            if (!$e->isConflict()) {
                $this->handleFailure($record, $e, $this->module->l('Nie udało się anulować zamówienia w BillTo', 'ordersync'));
            }
        }
    }

    public function sendEmail(int $idOrder): ?string
    {
        $record = OrderRecord::find($idOrder);

        if ($record === null || $record->invoiceId === '') {
            return null;
        }

        $order = new Order($idOrder);
        $email = $this->adapter->buyer($order)->email;

        try {
            $response = $this->clientFor()->post(ApiPaths::invoiceSendEmail($record->invoiceId), ['email' => $email]);
        } catch (ApiException $e) {
            $record->rememberError($e->getMessage());

            return null;
        }

        return isset($response['data']['sent_to']) ? (string) $response['data']['sent_to'] : $email;
    }

    // ---- Internals -----------------------------------------------------------------------------

    /**
     * @throws RetryableFailure
     */
    private function viesAllows(Order $order, OrderRecord $record): bool
    {
        $settings = $this->config->toSettings();
        $buyer = $this->adapter->buyer($order);

        if (BuyerScenario::forBuyer($buyer) !== BuyerScenario::EU_B2B || $settings->viesCheck === Settings::VIES_OFF) {
            return true;
        }

        $cached = $record->vies;
        $result = is_array($cached) && in_array(isset($cached['status']) ? $cached['status'] : '', [Vies::VALID, Vies::INVALID], true)
            ? $cached
            : Vies::check($buyer->taxId, new CurlHttp());

        if ($result['status'] === Vies::UNAVAILABLE) {
            $record->rememberError($this->module->l('VIES niedostępny - weryfikacja zostanie powtórzona.', 'ordersync'));

            throw new RetryableFailure('VIES unavailable');
        }

        $record->vies = $result;
        $record->save();

        if ($result['status'] === Vies::INVALID && $settings->viesCheck === Settings::VIES_BLOCK) {
            $record->rememberError($this->module->l('Numer VAT UE nabywcy jest nieaktywny w VIES - faktura 0% WDT nie zostanie wystawiona.', 'ordersync'));

            return false;
        }

        return true;
    }

    private function recoverInvoice(OrderRecord $record, string $billtoId): void
    {
        try {
            $response = $this->clientFor()->get(ApiPaths::order($billtoId));
        } catch (ApiException $e) {
            $this->handleFailure($record, $e, $this->module->l('Nie udało się odczytać zamówienia z BillTo', 'ordersync'));

            return;
        }

        $invoices = isset($response['data']['invoices']) && is_array($response['data']['invoices']) ? $response['data']['invoices'] : [];

        foreach ($invoices as $invoice) {
            if (in_array(isset($invoice['type']) ? $invoice['type'] : '', ['VAT', 'OSS'], true) && (isset($invoice['status']) ? $invoice['status'] : '') !== 'cancelled') {
                $this->storeInvoice($record, $invoice);

                return;
            }
        }

        $record->rememberError($this->module->l('BillTo zgłasza zafakturowane zamówienie, ale nie znaleziono faktury.', 'ordersync'));
    }

    /**
     * @throws RetryableFailure
     */
    private function handleFailure(OrderRecord $record, ApiException $e, string $context): void
    {
        if ($e->isRetryable()) {
            $record->rememberError($context . ': ' . $e->getMessage());

            throw new RetryableFailure($e->getMessage(), 0, $e);
        }

        $details = $e->isValidation() ? implode('; ', $e->validationMessages()) : '';
        $record->rememberError($context . ': ' . $e->getMessage() . ($details !== '' ? ' (' . $details . ')' : ''));
        $this->logger->error(sprintf('Order #%d: %s: %s', $record->idOrder, $context, $e->getMessage()));
    }

    private function dispatch(string $job, int $idOrder, int $delaySeconds = 0): void
    {
        Queue::dispatch($this->config, $job, $idOrder, $delaySeconds, $this->module);
    }

    private function clientFor(): Client
    {
        return call_user_func($this->client);
    }
}
