<?php

namespace BillTo\PrestaShop\Sync;

use BillTo\PrestaShop\Support\Config;
use BillTo\PrestaShop\Support\Logger;
use BillTo\PrestaShop\Support\OrderRecord;
use BillTo\Shop\KsefStatus;
use Db;
use DbQuery;

/**
 * Job table `billto_job` + runner. PrestaShop has no job scheduler, so:
 * - "immediate" mode runs the job right away (inside the hook, errors caught) and only queues retries;
 * - "cron" mode queues everything; a cron URL (module front controller, token-protected) runs due jobs.
 */
final class Queue
{
    const TABLE = 'billto_job';

    const JOB_SYNC = 'sync';

    const JOB_INVOICE = 'invoice';

    const JOB_SETTLE = 'settle';

    const JOB_CANCEL = 'cancel';

    const JOB_REFUND = 'refund';

    const JOB_KSEF_STATUS = 'ksef_status';

    /** Retry delays in seconds. */
    const BACKOFF = [60, 300, 1800, 7200, 43200];

    /** KSeF poll delays in seconds. */
    const KSEF_POLL = [60, 300, 1800, 7200];

    /** @var Config */
    private $config;

    /** @var OrderSync */
    private $orderSync;

    /** @var RefundSync */
    private $refundSync;

    /** @var Logger */
    private $logger;

    public function __construct(Config $config, OrderSync $orderSync, RefundSync $refundSync, Logger $logger)
    {
        $this->config = $config;
        $this->orderSync = $orderSync;
        $this->refundSync = $refundSync;
        $this->logger = $logger;
    }

    /**
     * Queue a job (or run it now in immediate mode). $payload carries e.g. the refund id.
     *
     * @param array<string, mixed> $payload
     */
    public static function dispatch(Config $config, string $job, int $idOrder, int $delaySeconds = 0, ?\Module $module = null, array $payload = []): void
    {
        if ($delaySeconds === 0 && $config->syncImmediately() && $module instanceof \BilltoInvoices) {
            try {
                $module->queue()->run($job, $idOrder, $payload, 0);
            } catch (\Throwable $e) {
                (new Logger())->error('Immediate job ' . $job . ' failed: ' . $e->getMessage());
            }

            return;
        }

        self::enqueue($job, $idOrder, $payload, $delaySeconds, 0);
    }

    /**
     * @param array<string, mixed> $payload
     */
    public static function enqueue(string $job, int $idOrder, array $payload, int $delaySeconds, int $attempt): void
    {
        // Deduplicate identical pending jobs.
        $query = new DbQuery();
        $query->select('id_job')->from(self::TABLE)
            ->where('job = "' . pSQL($job) . '"')
            ->where('id_order = ' . (int) $idOrder)
            ->where('payload = "' . pSQL(json_encode($payload)) . '"')
            ->where('status = "pending"');

        if ($delaySeconds === 0 && Db::getInstance()->getValue($query)) {
            return;
        }

        Db::getInstance()->insert(self::TABLE, [
            'job' => pSQL($job),
            'id_order' => (int) $idOrder,
            'payload' => pSQL(json_encode($payload)),
            'attempt' => (int) $attempt,
            'status' => 'pending',
            'run_at' => date('Y-m-d H:i:s', time() + $delaySeconds),
            'date_add' => date('Y-m-d H:i:s'),
        ]);
    }

    /**
     * Runs due jobs (cron). Returns the number of processed jobs.
     */
    public function runDue(int $limit = 20): int
    {
        $query = new DbQuery();
        $query->select('*')->from(self::TABLE)
            ->where('status = "pending"')
            ->where('run_at <= "' . pSQL(date('Y-m-d H:i:s')) . '"')
            ->orderBy('run_at ASC')
            ->limit($limit);

        $rows = Db::getInstance()->executeS($query);
        $processed = 0;

        foreach (is_array($rows) ? $rows : [] as $row) {
            Db::getInstance()->update(self::TABLE, ['status' => 'running'], 'id_job = ' . (int) $row['id_job']);

            try {
                $payload = json_decode((string) $row['payload'], true);
                $this->run((string) $row['job'], (int) $row['id_order'], is_array($payload) ? $payload : [], (int) $row['attempt']);
                Db::getInstance()->update(self::TABLE, ['status' => 'done', 'date_upd' => date('Y-m-d H:i:s')], 'id_job = ' . (int) $row['id_job']);
            } catch (\Throwable $e) {
                Db::getInstance()->update(self::TABLE, ['status' => 'failed', 'error' => pSQL(mb_substr($e->getMessage(), 0, 500)), 'date_upd' => date('Y-m-d H:i:s')], 'id_job = ' . (int) $row['id_job']);
                $this->logger->error('Job ' . $row['job'] . ' #' . $row['id_order'] . ' failed: ' . $e->getMessage());
            }

            ++$processed;
        }

        return $processed;
    }

    /**
     * Executes one job; transient failures re-queue with backoff, KSeF polling re-queues while pending.
     *
     * @param array<string, mixed> $payload
     */
    public function run(string $job, int $idOrder, array $payload, int $attempt): void
    {
        try {
            switch ($job) {
                case self::JOB_SYNC:
                    $this->orderSync->createOrUpdateBillToOrder($idOrder);
                    break;
                case self::JOB_INVOICE:
                    $this->orderSync->invoiceOrder($idOrder);
                    break;
                case self::JOB_SETTLE:
                    $this->orderSync->settleInvoice($idOrder);
                    break;
                case self::JOB_CANCEL:
                    $this->orderSync->cancelBillToOrder($idOrder);
                    break;
                case self::JOB_REFUND:
                    $this->refundSync->correctForSlip($idOrder, (int) (isset($payload['id_order_slip']) ? $payload['id_order_slip'] : 0));
                    break;
                case self::JOB_KSEF_STATUS:
                    $record = OrderRecord::find($idOrder);

                    if ($record !== null) {
                        $data = $this->orderSync->refreshKsefStatus($record);
                        $poll = (int) (isset($payload['poll']) ? $payload['poll'] : 0);

                        if ($data !== null && KsefStatus::of($data) === KsefStatus::PENDING && isset(self::KSEF_POLL[$poll + 1])) {
                            self::enqueue(self::JOB_KSEF_STATUS, $idOrder, ['poll' => $poll + 1], self::KSEF_POLL[$poll + 1], 0);
                        }
                    }
                    break;
            }
        } catch (RetryableFailure $e) {
            if (!isset(self::BACKOFF[$attempt])) {
                $this->logger->error(sprintf('%s #%d: giving up after %d attempts (%s)', $job, $idOrder, $attempt + 1, $e->getMessage()));

                return;
            }

            self::enqueue($job, $idOrder, $payload, self::BACKOFF[$attempt], $attempt + 1);
        }
    }

    /**
     * @return array{pending: int, failed: int}
     */
    public static function stats(): array
    {
        $pending = (int) Db::getInstance()->getValue('SELECT COUNT(*) FROM `' . _DB_PREFIX_ . self::TABLE . '` WHERE status = "pending"');
        $failed = (int) Db::getInstance()->getValue('SELECT COUNT(*) FROM `' . _DB_PREFIX_ . self::TABLE . '` WHERE status = "failed" AND date_add >= "' . pSQL(date('Y-m-d H:i:s', time() - 7 * 86400)) . '"');

        return ['pending' => $pending, 'failed' => $failed];
    }
}
