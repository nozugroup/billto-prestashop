<?php

namespace BillTo\Shop\Model;

/**
 * Shop order in the shape the core understands. Each plugin maps its platform's order onto this.
 */
final class Order
{
    /** @var string Platform order id (BillTo `external_id`) */
    public $externalId;

    /** @var string Human order number for notes */
    public $number;

    /** @var string ISO 4217 */
    public $currency;

    /** @var string Y-m-d */
    public $date;

    /** @var Buyer */
    public $buyer;

    /** @var Line[] */
    public $lines;

    /** @var string Payment gateway id as known to the platform */
    public $paymentGatewayId;

    /** @var string Payment method title for notes */
    public $paymentTitle;

    /** @var string Customer note */
    public $customerNote;

    /**
     * @param Line[] $lines
     */
    public function __construct(
        string $externalId,
        string $number,
        string $currency,
        string $date,
        Buyer $buyer,
        array $lines,
        string $paymentGatewayId = '',
        string $paymentTitle = '',
        string $customerNote = ''
    ) {
        $this->externalId = $externalId;
        $this->number = $number;
        $this->currency = $currency;
        $this->date = $date;
        $this->buyer = $buyer;
        $this->lines = array_values($lines);
        $this->paymentGatewayId = $paymentGatewayId;
        $this->paymentTitle = $paymentTitle;
        $this->customerNote = $customerNote;
    }

    /** Gross total of all lines (what the customer paid). */
    public function grossTotal(): float
    {
        $total = 0.0;

        foreach ($this->lines as $line) {
            $total += $line->grossTotal;
        }

        return round($total, 2);
    }

    public function hasGoods(): bool
    {
        foreach ($this->lines as $line) {
            if ($line->isProduct() && ! $line->isService && $line->quantity > 0) {
                return true;
            }
        }

        return false;
    }
}
