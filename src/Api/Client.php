<?php

namespace BillTo\PrestaShop\Api;

use BillTo\PrestaShop\Support\Logger;
use BillTo\PrestaShop\Support\Version;

/**
 * Thin BillTo API v1 client on curl: Bearer auth, Idempotency-Key on every mutation, one retry
 * for transient failures, typed errors. No Guzzle - PrestaShop ships its own, version-dependent copy.
 */
class Client
{
    const TIMEOUT = 30;

    /** @var string */
    private $token;

    /** @var string */
    private $baseUrl;

    /** @var Logger */
    private $logger;

    public function __construct(string $token, string $baseUrl, Logger $logger)
    {
        $this->token = $token;
        $this->baseUrl = rtrim($baseUrl, '/');
        $this->logger = $logger;
    }

    public function isConfigured(): bool
    {
        return trim($this->token) !== '';
    }

    /**
     * @param array<string, mixed> $query
     * @return array<string, mixed>
     */
    public function get(string $path, array $query = []): array
    {
        return $this->request('GET', $path, $query);
    }

    /**
     * @param array<string, mixed>|null $body
     * @return array<string, mixed>
     */
    public function post(string $path, ?array $body = null, ?string $idempotencyKey = null): array
    {
        return $this->request('POST', $path, [], $body === null ? [] : $body, $idempotencyKey !== null ? $idempotencyKey : self::randomKey());
    }

    /**
     * @param array<string, mixed> $body
     * @return array<string, mixed>
     */
    public function put(string $path, array $body, ?string $idempotencyKey = null): array
    {
        return $this->request('PUT', $path, [], $body, $idempotencyKey !== null ? $idempotencyKey : self::randomKey());
    }

    public function download(string $path): string
    {
        $response = $this->send('GET', $path, [], null, null, '*/*');

        if ($response === null) {
            throw new ApiException('Could not reach the BillTo API', 0);
        }

        if ($response['status'] >= 400) {
            throw $this->exceptionFrom($response);
        }

        return $response['body'];
    }

    /** Stable key per business operation, namespaced per shop. */
    public static function operationKey(string $operation): string
    {
        return hash('sha256', _COOKIE_KEY_ . '|billto-ps|' . $operation);
    }

    public static function randomKey(): string
    {
        return bin2hex(random_bytes(16));
    }

    /**
     * @param array<string, mixed> $query
     * @param array<string, mixed>|null $body
     * @return array<string, mixed>
     */
    private function request(string $method, string $path, array $query = [], ?array $body = null, ?string $idempotencyKey = null): array
    {
        $attempt = 0;

        while (true) {
            $response = $this->send($method, $path, $query, $body, $idempotencyKey);
            $exception = null;

            if ($response === null) {
                $exception = new ApiException('Could not reach the BillTo API', 0);
            } elseif ($response['status'] < 400) {
                $decoded = json_decode($response['body'], true);

                return is_array($decoded) ? $decoded : [];
            } else {
                $exception = $this->exceptionFrom($response);
            }

            if ($attempt === 0 && $exception->isRetryable()) {
                ++$attempt;
                $retryAfter = $response !== null && isset($response['headers']['retry-after']) ? (int) $response['headers']['retry-after'] : 1;
                $this->pause(max(1, min($retryAfter, 5)));

                continue;
            }

            $this->logger->error(sprintf('%s %s failed: HTTP %d %s', $method, $path, $exception->status(), $exception->getMessage()));

            throw $exception;
        }
    }

    protected function pause(int $seconds): void
    {
        sleep($seconds);
    }

    /**
     * @param array<string, mixed> $query
     * @param array<string, mixed>|null $body
     * @return array{status: int, body: string, headers: array<string, string>}|null
     */
    protected function send(string $method, string $path, array $query, ?array $body, ?string $idempotencyKey, string $accept = 'application/json'): ?array
    {
        $url = $this->baseUrl . '/' . ltrim($path, '/');
        $query = array_filter($query, static function ($value) {
            return $value !== null;
        });

        if ($query !== []) {
            foreach ($query as $key => $value) {
                if (is_bool($value)) {
                    $query[$key] = $value ? '1' : '0';
                }
            }

            $url .= (strpos($url, '?') !== false ? '&' : '?') . http_build_query($query, '', '&', PHP_QUERY_RFC3986);
        }

        $headers = [
            'Authorization: Bearer ' . $this->token,
            'Accept: ' . $accept,
            // The name must match the module's technical id (directory, ZIP, slug on the BillTo
            // side): BillTo recognises installations by it and compares the reported version.
            'User-Agent: billtoinvoices/' . Version::MODULE . ' PrestaShop/' . (defined('_PS_VERSION_') ? _PS_VERSION_ : '?'),
        ];

        if ($idempotencyKey !== null) {
            $headers[] = 'Idempotency-Key: ' . $idempotencyKey;
        }

        $ch = curl_init($url);

        if ($ch === false) {
            return null;
        }

        $responseHeaders = [];
        $options = [
            CURLOPT_CUSTOMREQUEST => $method,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => self::TIMEOUT,
            CURLOPT_HEADERFUNCTION => static function ($ch, $line) use (&$responseHeaders) {
                $parts = explode(':', $line, 2);

                if (count($parts) === 2) {
                    $responseHeaders[strtolower(trim($parts[0]))] = trim($parts[1]);
                }

                return strlen($line);
            },
        ];

        if ($body !== null) {
            $headers[] = 'Content-Type: application/json';
            $options[CURLOPT_POSTFIELDS] = json_encode($body, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        }

        $options[CURLOPT_HTTPHEADER] = $headers;
        curl_setopt_array($ch, $options);

        $responseBody = curl_exec($ch);
        $status = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
        $error = curl_error($ch);
        curl_close($ch);

        if ($responseBody === false) {
            $this->logger->warning('curl: ' . $error);

            return null;
        }

        return ['status' => $status, 'body' => (string) $responseBody, 'headers' => $responseHeaders];
    }

    /**
     * @param array{status: int, body: string, headers: array<string, string>} $response
     */
    private function exceptionFrom(array $response): ApiException
    {
        $decoded = json_decode($response['body'], true);
        $body = is_array($decoded) ? $decoded : [];
        $message = isset($body['message']) && is_string($body['message']) ? $body['message'] : ('BillTo API error (HTTP ' . $response['status'] . ')');

        return new ApiException($message, $response['status'], $body);
    }
}
