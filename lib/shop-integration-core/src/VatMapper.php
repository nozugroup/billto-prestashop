<?php

namespace BillTo\Shop;

use BillTo\Shop\Model\Line;
use BillTo\Shop\Model\Order;

/**
 * Maps shop tax rates onto BillTo `vat_type` values per buyer scenario.
 */
final class VatMapper
{
    const POLISH_RATES = [23, 22, 8, 7, 5, 4, 3];

    /**
     * `vat_type` of a line for the given scenario.
     *
     * @param bool $isService Service (np) vs goods (0% WDT / 0% EX) for foreign B2B; shipping follows the goods.
     */
    public static function forLine(Line $line, string $scenario, bool $isService, Settings $settings): string
    {
        switch ($scenario) {
            case BuyerScenario::EU_B2B:
                return $isService ? 'np I' : '0 WDT';
            case BuyerScenario::NON_EU:
                return $isService ? 'np II' : '0 EX';
            default:
                return self::domestic($line->taxPercent, $settings);
        }
    }

    /**
     * Polish `vat_type` for a shop tax percentage (null = no tax applied).
     */
    public static function domestic(?float $percent, Settings $settings): string
    {
        if ($percent === null) {
            return $settings->vatTypeForNoTax;
        }

        if ($percent <= 0.0) {
            return $settings->vatTypeForZeroRate;
        }

        $rounded = (int) round($percent);

        if (in_array($rounded, self::POLISH_RATES, true)) {
            return (string) $rounded;
        }

        if ($settings->unknownRateMapper !== null) {
            $mapped = call_user_func($settings->unknownRateMapper, $percent);

            if (is_string($mapped) && $mapped !== '') {
                return $mapped;
            }
        }

        return $percent >= 15 ? '23' : ($percent >= 6 ? '8' : '5');
    }

    /**
     * Consumption-country VAT rate for an OSS invoice as a numeric string ("19", "13.5"): the single
     * positive tax percentage the shop applied. Null for no tax, 0% or mixed rates (BillTo then
     * applies the standard rate of the buyer's country).
     */
    public static function ossRate(Order $order): ?string
    {
        $rates = [];

        foreach ($order->lines as $line) {
            if ($line->taxPercent !== null && $line->taxPercent > 0) {
                $rates[number_format($line->taxPercent, 2, '.', '')] = true;
            }
        }

        if (count($rates) !== 1) {
            return null;
        }

        $keys = array_keys($rates);

        return rtrim(rtrim((string) $keys[0], '0'), '.');
    }
}
