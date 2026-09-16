<?php

namespace BillTo\Shop\Vies;

use BillTo\Shop\Nip;

/**
 * EU VAT number check against the European Commission VIES REST API. No authentication; the
 * service is occasionally unavailable, which is reported as UNAVAILABLE so callers can retry
 * instead of treating it as invalid.
 */
final class Vies
{
    const VALID = 'valid';

    const INVALID = 'invalid';

    const UNAVAILABLE = 'unavailable';

    const URL = 'https://ec.europa.eu/taxation_customs/vies/rest-api/ms/%s/vat/%s';

    const TRANSIENT_ERRORS = ['MS_UNAVAILABLE', 'MS_MAX_CONCURRENT_REQ', 'SERVICE_UNAVAILABLE', 'TIMEOUT', 'GLOBAL_MAX_CONCURRENT_REQ'];

    /**
     * @return array{status: string, name: string|null, checked_at: string}
     */
    public static function check(string $vatId, HttpGetInterface $http): array
    {
        $now = gmdate('c');
        $normalized = Nip::normalizeEuVat($vatId);

        if ($normalized === null) {
            return ['status' => self::INVALID, 'name' => null, 'checked_at' => $now];
        }

        $country = substr($normalized, 0, 2);
        $number = substr($normalized, 2);

        if ($country === 'GR') {
            $country = 'EL'; // VIES uses EL for Greece
        }

        $response = $http->get(sprintf(self::URL, rawurlencode($country), rawurlencode($number)));

        if ($response === null || $response['status'] >= 500) {
            return ['status' => self::UNAVAILABLE, 'name' => null, 'checked_at' => $now];
        }

        return self::interpret($response['body'], $now);
    }

    /**
     * @return array{status: string, name: string|null, checked_at: string}
     */
    public static function interpret(string $body, ?string $checkedAt = null): array
    {
        $now = $checkedAt !== null ? $checkedAt : gmdate('c');
        $data = json_decode($body, true);

        if (! is_array($data)) {
            return ['status' => self::UNAVAILABLE, 'name' => null, 'checked_at' => $now];
        }

        $userError = strtoupper((string) (isset($data['userError']) ? $data['userError'] : 'VALID'));

        if (in_array($userError, self::TRANSIENT_ERRORS, true)) {
            return ['status' => self::UNAVAILABLE, 'name' => null, 'checked_at' => $now];
        }

        $valid = ! empty($data['isValid']) || ! empty($data['valid']);
        $name = isset($data['name']) && is_string($data['name']) && $data['name'] !== '---' ? $data['name'] : null;

        return ['status' => $valid ? self::VALID : self::INVALID, 'name' => $name, 'checked_at' => $now];
    }
}
