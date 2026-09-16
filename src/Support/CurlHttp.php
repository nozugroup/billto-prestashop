<?php

namespace BillTo\PrestaShop\Support;

use BillTo\Shop\Vies\HttpGetInterface;

/**
 * curl-based GET for the core's VIES check (ext-curl is part of every PrestaShop requirement list).
 */
final class CurlHttp implements HttpGetInterface
{
    public function get(string $url): ?array
    {
        $ch = curl_init($url);

        if ($ch === false) {
            return null;
        }

        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => 10,
            CURLOPT_HTTPHEADER => ['Accept: application/json'],
        ]);

        $body = curl_exec($ch);
        $status = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
        curl_close($ch);

        if ($body === false) {
            return null;
        }

        return ['status' => $status, 'body' => (string) $body];
    }
}
