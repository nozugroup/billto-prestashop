<?php

namespace BillTo\Shop\Model;

/**
 * One line of a shop order: a product, a shipping charge or a fee.
 *
 * Amounts are LINE totals after discounts (not unit prices); the payload builder derives unit
 * prices. A negative total (discount fee, gift card) is allowed here and handled by the builder.
 */
final class Line
{
    const TYPE_PRODUCT = 'product';

    const TYPE_SHIPPING = 'shipping';

    const TYPE_FEE = 'fee';

    /** @var string Platform item id, used to map BillTo line numbers back (refunds) */
    public $id;

    /** @var string */
    public $type;

    /** @var string */
    public $name;

    /** @var float */
    public $quantity;

    /** @var float Net line total after discounts */
    public $netTotal;

    /** @var float Gross line total after discounts */
    public $grossTotal;

    /** @var float|null Tax percentage applied by the shop, null when no tax was applied at all */
    public $taxPercent;

    /** @var bool Service (as opposed to goods) - decides np vs 0% on foreign B2B invoices */
    public $isService;

    public function __construct(
        string $id,
        string $type,
        string $name,
        float $quantity,
        float $netTotal,
        float $grossTotal,
        ?float $taxPercent,
        bool $isService = false
    ) {
        $this->id = $id;
        $this->type = $type;
        $this->name = $name;
        $this->quantity = $quantity;
        $this->netTotal = $netTotal;
        $this->grossTotal = $grossTotal;
        $this->taxPercent = $taxPercent;
        $this->isService = $isService;
    }

    public function isProduct(): bool
    {
        return $this->type === self::TYPE_PRODUCT;
    }
}
