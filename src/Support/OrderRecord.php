<?php

namespace BillTo\PrestaShop\Support;

use Db;
use DbQuery;

/**
 * Row of `billto_order`: everything the module remembers about a PrestaShop order in BillTo.
 */
final class OrderRecord
{
    const TABLE = 'billto_order';

    /** @var int */
    public $idOrder;

    /** @var string */
    public $billtoOrderId = '';

    /** @var string */
    public $billtoOrderNumber = '';

    /** @var array<string, int> */
    public $lineMap = [];

    /** @var string */
    public $invoiceId = '';

    /** @var string */
    public $invoiceNumber = '';

    /** @var bool|null null = unknown */
    public $invoicePaid = null;

    /** @var string */
    public $publicUrl = '';

    /** @var string */
    public $pdfPath = '';

    /** @var string */
    public $ksefStatus = '';

    /** @var string */
    public $ksefNumber = '';

    /** @var array<string, mixed>|null */
    public $vies = null;

    /** @var string */
    public $lastError = '';

    /** @var array<string, array<string, mixed>> refund id => correction */
    public $corrections = [];

    /** @var string */
    public $scenario = '';

    public function __construct(int $idOrder)
    {
        $this->idOrder = $idOrder;
    }

    public static function find(int $idOrder): ?self
    {
        $query = new DbQuery();
        $query->select('*')->from(self::TABLE)->where('id_order = ' . (int) $idOrder);
        $row = Db::getInstance()->getRow($query);

        return is_array($row) ? self::fromRow($row) : null;
    }

    public static function findOrNew(int $idOrder): self
    {
        $record = self::find($idOrder);

        return $record !== null ? $record : new self($idOrder);
    }

    /**
     * @param array<string, mixed> $row
     */
    private static function fromRow(array $row): self
    {
        $record = new self((int) $row['id_order']);
        $record->billtoOrderId = (string) $row['billto_order_id'];
        $record->billtoOrderNumber = (string) $row['billto_order_number'];
        $record->lineMap = self::decode($row['line_map']);
        $record->invoiceId = (string) $row['invoice_id'];
        $record->invoiceNumber = (string) $row['invoice_number'];
        $record->invoicePaid = $row['invoice_paid'] === null ? null : (bool) $row['invoice_paid'];
        $record->publicUrl = (string) $row['public_url'];
        $record->pdfPath = (string) $row['pdf_path'];
        $record->ksefStatus = (string) $row['ksef_status'];
        $record->ksefNumber = (string) $row['ksef_number'];
        $vies = self::decode($row['vies']);
        $record->vies = $vies === [] ? null : $vies;
        $record->lastError = (string) $row['last_error'];
        $record->corrections = self::decode($row['corrections']);
        $record->scenario = (string) $row['scenario'];

        return $record;
    }

    public function save(): void
    {
        $data = [
            'id_order' => (int) $this->idOrder,
            'billto_order_id' => pSQL($this->billtoOrderId),
            'billto_order_number' => pSQL($this->billtoOrderNumber),
            'line_map' => pSQL(json_encode($this->lineMap)),
            'invoice_id' => pSQL($this->invoiceId),
            'invoice_number' => pSQL($this->invoiceNumber),
            'invoice_paid' => $this->invoicePaid === null ? null : (int) $this->invoicePaid,
            'public_url' => pSQL($this->publicUrl),
            'pdf_path' => pSQL($this->pdfPath),
            'ksef_status' => pSQL($this->ksefStatus),
            'ksef_number' => pSQL($this->ksefNumber),
            'vies' => $this->vies === null ? null : pSQL(json_encode($this->vies)),
            'last_error' => pSQL(mb_substr($this->lastError, 0, 1000)),
            'corrections' => pSQL(json_encode($this->corrections)),
            'scenario' => pSQL($this->scenario),
            'date_upd' => date('Y-m-d H:i:s'),
        ];

        if (self::find($this->idOrder) === null) {
            $data['date_add'] = date('Y-m-d H:i:s');
            Db::getInstance()->insert(self::TABLE, $data, true);
        } else {
            Db::getInstance()->update(self::TABLE, $data, 'id_order = ' . (int) $this->idOrder, 0, true);
        }
    }

    public function rememberError(string $message): void
    {
        $this->lastError = $message;
        $this->save();
    }

    public function clearError(): void
    {
        if ($this->lastError !== '') {
            $this->lastError = '';
            $this->save();
        }
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'billto_order_id' => $this->billtoOrderId,
            'billto_order_number' => $this->billtoOrderNumber,
            'invoice_id' => $this->invoiceId,
            'invoice_number' => $this->invoiceNumber,
            'invoice_paid' => $this->invoicePaid,
            'public_url' => $this->publicUrl,
            'has_pdf' => $this->pdfPath !== '',
            'ksef_status' => $this->ksefStatus,
            'ksef_number' => $this->ksefNumber,
            'last_error' => $this->lastError,
            'corrections' => $this->corrections,
            'scenario' => $this->scenario,
            'vies' => $this->vies,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private static function decode($json): array
    {
        $decoded = json_decode((string) $json, true);

        return is_array($decoded) ? $decoded : [];
    }
}
