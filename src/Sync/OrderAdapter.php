<?php

namespace BillTo\PrestaShop\Sync;

use Address;
use BillTo\PrestaShop\Support\Config;
use BillTo\Shop\Model\Buyer;
use BillTo\Shop\Model\Line;
use BillTo\Shop\Model\Order as CoreOrder;
use Carrier;
use Country;
use Currency;
use Customer;
use Order;

/**
 * Maps a PrestaShop Order onto the platform-neutral order model of the shared core.
 *
 * Cart-rule discounts are not lines in PrestaShop; they become a negative fee line so the core
 * spreads them over the products (BillTo lines cannot be negative). Gift wrapping becomes a fee.
 */
class OrderAdapter
{
    /** @var Config */
    private $config;

    public function __construct(Config $config)
    {
        $this->config = $config;
    }

    public function toCoreOrder(Order $order): CoreOrder
    {
        $currency = new Currency((int) $order->id_currency);

        return new CoreOrder(
            (string) $order->id,
            (string) $order->reference,
            (string) $currency->iso_code,
            substr((string) $order->date_add, 0, 10),
            $this->buyer($order),
            $this->lines($order),
            (string) $order->module,
            (string) $order->payment,
            ''
        );
    }

    public function buyer(Order $order): Buyer
    {
        $address = new Address((int) $order->id_address_invoice);
        $customer = new Customer((int) $order->id_customer);
        $country = (string) Country::getIsoById((int) $address->id_country);
        $company = trim((string) $address->company);
        $person = trim($address->firstname . ' ' . $address->lastname);
        $taxId = $this->config->taxIdField() === 'dni' ? (string) $address->dni : (string) $address->vat_number;

        return new Buyer(
            $company !== '' ? $company : $person,
            $country,
            trim($taxId),
            trim($address->address1 . ' ' . $address->address2),
            trim($address->postcode . ' ' . $address->city),
            (string) $customer->email,
            trim((string) ($address->phone_mobile !== '' ? $address->phone_mobile : $address->phone))
        );
    }

    /**
     * @return Line[]
     */
    public function lines(Order $order): array
    {
        $lines = [];

        foreach ($order->getProducts() as $row) {
            $quantity = (float) $row['product_quantity'];

            if ($quantity <= 0) {
                continue;
            }

            $lines[] = new Line(
                (string) $row['id_order_detail'],
                Line::TYPE_PRODUCT,
                $this->productName($row),
                $quantity,
                (float) $row['total_price_tax_excl'],
                (float) $row['total_price_tax_incl'],
                isset($row['tax_rate']) && $row['tax_rate'] !== null ? (float) $row['tax_rate'] : null,
                $this->isService($row)
            );
        }

        $shippingExcl = (float) $order->total_shipping_tax_excl;
        $shippingIncl = (float) $order->total_shipping_tax_incl;

        if (abs($shippingIncl) > 0.00001) {
            $carrier = new Carrier((int) $order->id_carrier);
            $lines[] = new Line('shipping', Line::TYPE_SHIPPING, (string) $carrier->name, 1.0, $shippingExcl, $shippingIncl, $this->percent($shippingExcl, $shippingIncl));
        }

        $wrappingExcl = (float) $order->total_wrapping_tax_excl;
        $wrappingIncl = (float) $order->total_wrapping_tax_incl;

        if (abs($wrappingIncl) > 0.00001) {
            $lines[] = new Line('wrapping', Line::TYPE_FEE, 'Pakowanie na prezent', 1.0, $wrappingExcl, $wrappingIncl, $this->percent($wrappingExcl, $wrappingIncl));
        }

        $discountExcl = (float) $order->total_discounts_tax_excl;
        $discountIncl = (float) $order->total_discounts_tax_incl;

        if ($discountIncl > 0.00001) {
            // Cart rules: a negative fee the core distributes over the products.
            $lines[] = new Line('discount', Line::TYPE_FEE, 'Rabat', 1.0, -$discountExcl, -$discountIncl, $this->percent($discountExcl, $discountIncl));
        }

        return $lines;
    }

    /**
     * @param array<string, mixed> $row order detail row
     */
    private function productName(array $row): string
    {
        $name = (string) $row['product_name'];
        $reference = isset($row['product_reference']) ? trim((string) $row['product_reference']) : '';

        if ($reference !== '' && strpos($name, $reference) === false) {
            $name .= ' [' . $reference . ']';
        }

        return $name;
    }

    /**
     * Virtual products (downloadable, no shipping) count as services for foreign B2B invoices.
     *
     * @param array<string, mixed> $row
     */
    protected function isService(array $row): bool
    {
        return !empty($row['is_virtual']);
    }

    /** Tax percentage from the incl/excl pair, null when equal (no tax). */
    private function percent(float $excl, float $incl): ?float
    {
        if (abs($excl) < 0.00001) {
            return abs($incl) < 0.00001 ? null : 0.0;
        }

        $percent = round(($incl / $excl - 1) * 100, 2);

        return $percent <= 0.0 ? 0.0 : $percent;
    }
}
