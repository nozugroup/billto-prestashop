<?php

namespace BillTo\Shop;

/**
 * Builds `lines[]` for `POST /orders/{id}/issue-correction`. BillTo expects the state AFTER the
 * correction per invoice line: the remaining quantity, or the reduced unit price.
 */
final class RefundLines
{
    /**
     * Quantity-based refund. Each entry: platform line id, original quantity, quantity refunded in
     * total so far INCLUDING this refund (cumulative), and whether it is a product line
     * (shipping / fees count as quantity 1).
     *
     * @param array<string, int> $lineMap platform line id => BillTo line_number
     * @param array<int, array{id: string, originalQuantity: float, refundedQuantity: float, isProduct: bool}> $refunded
     * @return array<int, array{line_number: int, quantity: float}>
     */
    public static function remaining(array $lineMap, array $refunded): array
    {
        $lines = [];

        foreach ($refunded as $entry) {
            $id = (string) $entry['id'];

            if (! isset($lineMap[$id]) || $entry['refundedQuantity'] <= 0) {
                continue;
            }

            $original = $entry['isProduct'] ? (float) $entry['originalQuantity'] : 1.0;
            $refundedQty = $entry['isProduct'] ? (float) $entry['refundedQuantity'] : 1.0;
            $remaining = max(0.0, $original - $refundedQty);

            $lines[] = ['line_number' => $lineMap[$id], 'quantity' => round($remaining, 3)];
        }

        return $lines;
    }

    /**
     * Amount-only refund: lower every line's unit price by the same percentage of the order total.
     * Returns [] for a zero or full refund (a full refund should be a quantity refund).
     *
     * @param array<string, int> $lineMap platform line id => BillTo line_number
     * @param array<int, array{id: string, quantity: float, netTotal: float}> $lines current invoiced lines (net totals, quantity 1 for shipping/fees)
     * @return array<int, array{line_number: int, after_unit_price: float}>
     */
    public static function proportional(array $lineMap, array $lines, float $refundAmount, float $orderGrossTotal): array
    {
        if ($refundAmount <= 0 || $orderGrossTotal <= 0 || $refundAmount >= $orderGrossTotal) {
            return [];
        }

        $ratio = $refundAmount / $orderGrossTotal;
        $result = [];

        foreach ($lines as $line) {
            $id = (string) $line['id'];

            if (! isset($lineMap[$id]) || $line['netTotal'] <= 0) {
                continue;
            }

            $quantity = max(0.001, (float) $line['quantity']);
            $result[] = ['line_number' => $lineMap[$id], 'after_unit_price' => round($line['netTotal'] * (1 - $ratio) / $quantity, 4)];
        }

        return $result;
    }
}
