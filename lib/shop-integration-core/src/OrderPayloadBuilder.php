<?php

namespace BillTo\Shop;

use BillTo\Shop\Model\Line;
use BillTo\Shop\Model\Order;

/**
 * Builds the body of `POST /orders` (BillTo API v1) from a shop order.
 *
 * Result: `['payload' => array, 'lineMap' => array<string, int>, 'hasNegativeLines' => bool]`
 * where `lineMap` maps the platform line id to the BillTo `line_number` (1-based), needed later
 * to build refund corrections.
 */
final class OrderPayloadBuilder
{
    /** @var Settings */
    private $settings;

    public function __construct(Settings $settings)
    {
        $this->settings = $settings;
    }

    /**
     * `blocker` is a Settings::BLOCKER_* code when the order must not be sent as is (the plugin
     * records the matching message), null otherwise.
     *
     * @param string|null $viesStatus Result of the VIES check for EU companies, or null
     * @return array{payload: array<string, mixed>, lineMap: array<string, int>, hasNegativeLines: bool, scenario: string, blocker: string|null}
     */
    public function build(Order $order, ?string $viesStatus = null, bool $confirmed = true): array
    {
        $scenario = BuyerScenario::effective($order->buyer, $this->settings, $viesStatus);
        $lines = $this->lines($order, $scenario);
        $hasNegative = NegativeLines::hasNegative($lines);
        $blocker = $this->blocker($order, $scenario);

        if ($hasNegative && $this->settings->negativeLines === Settings::NEGATIVE_DISTRIBUTE) {
            $lines = NegativeLines::distribute($lines, $this->settings->discountSuffix);
        }

        $items = [];
        $lineMap = [];

        foreach (array_values($lines) as $index => $line) {
            $lineMap[(string) $line['_id']] = $index + 1;
            unset($line['_id'], $line['_net_total'], $line['_gross_total'], $line['_is_product']);
            $items[] = $line;
        }

        $payload = [
            'external_id' => $order->externalId,
            'source' => $this->settings->source,
            'status' => $confirmed ? 'confirmed' : 'draft',
            'send_confirmation' => false,
            'currency' => $order->currency,
            'order_date' => $order->date,
            'amount_entry_mode' => $this->settings->amountMode,
            'buyer' => $this->buyer($order),
            'items' => $items,
            'notes' => $this->notes($order),
        ];

        return ['payload' => $payload, 'lineMap' => $lineMap, 'hasNegativeLines' => $hasNegative, 'scenario' => $scenario, 'blocker' => $blocker];
    }

    /**
     * An EU consumer invoiced with Polish rates must carry VAT; a line without VAT means the shop
     * has no tax rule for that country (or a B2B-style 0% rule), which would silently produce a
     * zw / 0 KR invoice. Sellers exempt from VAT switch the setting to `map`.
     */
    private function blocker(Order $order, string $scenario): ?string
    {
        if ($scenario !== BuyerScenario::EU_B2C
            || $this->settings->ossMode !== Settings::OSS_PL_VAT
            || $this->settings->euConsumerNoVat !== Settings::EU_CONSUMER_NO_VAT_BLOCK) {
            return null;
        }

        foreach ($order->lines as $line) {
            if ($line->netTotal > 0 && ($line->taxPercent === null || $line->taxPercent <= 0.0)) {
                return Settings::BLOCKER_EU_CONSUMER_NO_VAT;
            }
        }

        return null;
    }

    /**
     * @return array<string, mixed>
     */
    public function buyer(Order $order): array
    {
        $buyer = $order->buyer;
        $identity = BuyerScenario::taxIdentity($buyer);

        $payload = [
            'name' => $buyer->name,
            'tax_type' => $identity[0],
            'tax_number' => $identity[1],
            'tax_country' => $identity[2],
            'country_code' => $buyer->country,
            'address_line_1' => $buyer->addressLine1 !== '' ? $buyer->addressLine1 : null,
            'address_line_2' => $buyer->addressLine2 !== '' ? $buyer->addressLine2 : null,
            'phone' => $buyer->phone !== '' ? substr($buyer->phone, 0, 32) : null,
            'email' => filter_var($buyer->email, FILTER_VALIDATE_EMAIL) !== false ? $buyer->email : null,
        ];

        return array_filter($payload, static function ($value) {
            return $value !== null && $value !== '';
        });
    }

    /**
     * Internal line arrays with `_id`, `_net_total`, `_gross_total`, `_is_product` markers.
     *
     * @return array<int, array<string, mixed>>
     */
    private function lines(Order $order, string $scenario): array
    {
        $gross = $this->settings->isGross();
        $hasGoods = $order->hasGoods();
        $result = [];

        foreach ($order->lines as $line) {
            if ($line->quantity <= 0 && $line->isProduct()) {
                continue;
            }

            if (! $line->isProduct() && abs($line->netTotal) < 0.00001) {
                continue; // free shipping / zero fee
            }

            $quantity = $line->isProduct() ? $line->quantity : 1.0;
            // Shipping and fees follow the goods; a services-only order has nothing to ship.
            $isService = $line->isProduct() ? $line->isService : ($line->type === Line::TYPE_FEE ? true : ! $hasGoods);
            $name = $line->type === Line::TYPE_SHIPPING ? $this->settings->shippingLabel.': '.$line->name : $line->name;

            $item = [
                'name' => mb_substr($name, 0, 255),
                'quantity' => round($quantity, 3),
                // Shipping and fees are services on the document even when their VAT follows the goods.
                'units' => $line->isProduct() && ! $line->isService ? 'szt.' : 'usł.',
                'unit_price' => round($line->netTotal / $quantity, 4),
                'vat_type' => VatMapper::forLine($line, $scenario, $isService, $this->settings),
                '_id' => $line->id,
                '_net_total' => round($line->netTotal, 2),
                '_gross_total' => round($line->grossTotal, 2),
                '_is_product' => $line->isProduct(),
            ];

            if ($gross) {
                $item['unit_price_gross'] = round($line->grossTotal / $quantity, 4);
            }

            $result[] = $item;
        }

        return $result;
    }

    private function notes(Order $order): string
    {
        $parts = [$this->settings->orderNoteLabel.' #'.$order->number];

        if ($order->paymentTitle !== '') {
            $parts[] = 'Płatność: '.$order->paymentTitle;
        }

        if (trim($order->customerNote) !== '') {
            $parts[] = mb_substr(trim($order->customerNote), 0, 1000);
        }

        return mb_substr(implode(' | ', $parts), 0, 5000);
    }
}
