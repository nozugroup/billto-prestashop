<?php

namespace BillTo\Shop;

/**
 * BillTo API v1 paths used by the shop plugins, in one place.
 */
final class ApiPaths
{
    const PRODUCTION = 'https://billto.pl/api/v1';

    const SANDBOX = 'https://sandbox.billto.pl/api/v1';

    public static function orders(): string
    {
        return 'orders';
    }

    public static function order(string $id): string
    {
        return 'orders/'.$id;
    }

    public static function markPaid(string $orderId): string
    {
        return 'orders/'.$orderId.'/mark-paid';
    }

    public static function cancel(string $orderId): string
    {
        return 'orders/'.$orderId.'/cancel';
    }

    public static function issueCorrection(string $orderId): string
    {
        return 'orders/'.$orderId.'/issue-correction';
    }

    public static function issueKor(string $correctingOrderId): string
    {
        return 'orders/'.$correctingOrderId.'/issue-kor';
    }

    public static function invoice(string $invoiceId): string
    {
        return 'invoices/'.$invoiceId;
    }

    public static function invoicePdf(string $invoiceId): string
    {
        return 'invoices/'.$invoiceId.'/pdf';
    }

    public static function invoiceKsef(string $invoiceId): string
    {
        return 'invoices/'.$invoiceId.'/ksef';
    }

    public static function invoiceMarkPaid(string $invoiceId): string
    {
        return 'invoices/'.$invoiceId.'/mark-paid';
    }

    public static function invoiceSendEmail(string $invoiceId): string
    {
        return 'invoices/'.$invoiceId.'/send-email';
    }

    public static function invoiceSeries(): string
    {
        return 'invoice-series';
    }
}
