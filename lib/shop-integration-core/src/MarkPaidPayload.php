<?php

namespace BillTo\Shop;

use BillTo\Shop\Model\Order;

/**
 * Body of `POST /orders/{id}/mark-paid` for a shop order: e-mail flag, payment flag, series,
 * OSS document and rate when the buyer is an EU consumer and the OSS mode is on.
 */
final class MarkPaidPayload
{
    /**
     * @return array<string, mixed>
     */
    public static function build(Order $order, Settings $settings): array
    {
        $payload = ['send_email' => $settings->billtoSendsEmail];

        if ($settings->isUnpaidGateway($order->paymentGatewayId)) {
            $payload['mark_paid'] = false;
        }

        $scenario = BuyerScenario::forBuyer($order->buyer);

        if ($scenario === BuyerScenario::EU_B2C && $settings->ossMode === Settings::OSS_INVOICE) {
            $payload['invoice_type'] = 'oss';
            $rate = VatMapper::ossRate($order);

            if ($rate !== null) {
                $payload['oss_vat_type'] = $rate;
            }

            if ($settings->ossSeriesId !== null && $settings->ossSeriesId !== '') {
                $payload['series_id'] = $settings->ossSeriesId;
            }

            return $payload;
        }

        if ($settings->seriesId !== null && $settings->seriesId !== '') {
            $payload['series_id'] = $settings->seriesId;
        }

        return $payload;
    }

    /**
     * Body of `POST /orders/{correcting}/issue-kor`.
     *
     * @return array<string, mixed>
     */
    public static function issueKor(Settings $settings): array
    {
        $payload = ['send_email' => $settings->billtoSendsEmail];

        if ($settings->korSeriesId !== null && $settings->korSeriesId !== '') {
            $payload['series_id'] = $settings->korSeriesId;
        }

        return $payload;
    }
}
