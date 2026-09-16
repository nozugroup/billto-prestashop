<?php

namespace BillTo\Shop;

/**
 * BillTo lines cannot be negative. Discount fees and gift cards are spread over the positive
 * PRODUCT lines in proportion to their value, so the invoice total still equals the amount paid.
 *
 * Works on the internal line arrays produced by {@see OrderPayloadBuilder} (with `_net_total`,
 * `_gross_total`, `_is_product` markers).
 */
final class NegativeLines
{
    /**
     * @param array<int, array<string, mixed>> $lines
     */
    public static function hasNegative(array $lines): bool
    {
        foreach ($lines as $line) {
            if ((float) (isset($line['_net_total']) ? $line['_net_total'] : 0) < 0) {
                return true;
            }
        }

        return false;
    }

    /**
     * Absorbs negative lines into positive product lines. The last positive line takes the rounding
     * remainder. When nothing positive is left the input is returned unchanged (the API will reject it).
     *
     * @param array<int, array<string, mixed>> $lines
     * @return array<int, array<string, mixed>>
     */
    public static function distribute(array $lines, string $discountSuffix = '(z rabatem)'): array
    {
        $negativeNet = 0.0;
        $negativeGross = 0.0;
        $positive = [];

        foreach ($lines as $index => $line) {
            $net = (float) (isset($line['_net_total']) ? $line['_net_total'] : 0);

            if ($net < 0) {
                $negativeNet += $net;
                $negativeGross += (float) (isset($line['_gross_total']) ? $line['_gross_total'] : $net);
            } elseif (! empty($line['_is_product']) && $net > 0) {
                $positive[$index] = $net;
            }
        }

        if ($negativeNet >= 0 || $positive === []) {
            return $lines;
        }

        $base = array_sum($positive);
        $remainingNet = -$negativeNet;
        $remainingGross = -$negativeGross;
        $positiveKeys = array_keys($positive);
        $last = end($positiveKeys);
        $result = [];

        foreach ($lines as $index => $line) {
            if ((float) (isset($line['_net_total']) ? $line['_net_total'] : 0) < 0) {
                continue; // absorbed
            }

            if (isset($positive[$index])) {
                $shareNet = $index === $last ? $remainingNet : round(-$negativeNet * $positive[$index] / $base, 2);
                $shareGross = $index === $last ? $remainingGross : round(-$negativeGross * $positive[$index] / $base, 2);
                $remainingNet -= $shareNet;
                $remainingGross -= $shareGross;

                $quantity = max(0.001, (float) $line['quantity']);
                $line['_net_total'] = round((float) $line['_net_total'] - $shareNet, 2);
                $line['_gross_total'] = round((float) $line['_gross_total'] - $shareGross, 2);
                $line['unit_price'] = round(max(0.0, (float) $line['_net_total']) / $quantity, 4);

                if (isset($line['unit_price_gross'])) {
                    $line['unit_price_gross'] = round(max(0.0, (float) $line['_gross_total']) / $quantity, 4);
                }

                if ($discountSuffix !== '') {
                    $line['name'] = mb_substr($line['name'].' '.$discountSuffix, 0, 255);
                }
            }

            $result[] = $line;
        }

        return $result;
    }
}
