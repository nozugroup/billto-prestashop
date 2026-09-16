<?php

namespace BillTo\PrestaShop\Support;

use BillTo\Shop\OAuth\HttpPostInterface;

/**
 * curl-based POST for the core's OAuth endpoints, alongside the existing GET used by VIES.
 *
 * PrestaShop ships its own Guzzle whose version differs between 1.7.x and 8.x, and modules that
 * bundle a second one break on the shops that have the other. ext-curl is on every PrestaShop
 * requirement list, so it is the one transport that is always there.
 */
final class CurlPost implements HttpPostInterface
{
    const TIMEOUT = 20;

    /**
     * @param array<string, string> $fields
     * @param array<string, string> $headers
     *
     * @return array{status: int, body: string}|null
     */
    public function post(string $url, array $fields, array $headers = []): ?array
    {
        $ch = curl_init($url);

        if ($ch === false) {
            return null;
        }

        $headerLines = ['Content-Type: application/x-www-form-urlencoded'];

        foreach ($headers as $name => $value) {
            $headerLines[] = $name . ': ' . $value;
        }

        $headerLines[] = 'User-Agent: ' . \BillTo\PrestaShop\Api\Client::userAgent();

        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => self::TIMEOUT,
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => http_build_query($fields, '', '&'),
            CURLOPT_HTTPHEADER => $headerLines,
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
