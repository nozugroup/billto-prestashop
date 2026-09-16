<?php

namespace BillTo\Shop;

/**
 * Interprets the `data` of `GET/POST /invoices/{id}/ksef`.
 */
final class KsefStatus
{
    const NONE = 'none';

    const PENDING = 'pending';

    const ASSIGNED = 'assigned';

    const ERROR = 'error';

    /**
     * @param array<string, mixed> $data
     */
    public static function of(array $data): string
    {
        if (isset($data['ksef_number']) && is_string($data['ksef_number']) && $data['ksef_number'] !== '') {
            return self::ASSIGNED;
        }

        if (! empty($data['has_unresolved_errors'])) {
            return self::ERROR;
        }

        return ! empty($data['sent']) ? self::PENDING : self::NONE;
    }

    /**
     * @param array<string, mixed> $data
     */
    public static function number(array $data): string
    {
        return isset($data['ksef_number']) && is_string($data['ksef_number']) ? $data['ksef_number'] : '';
    }

    /**
     * @param array<string, mixed> $data
     */
    public static function firstError(array $data): ?string
    {
        if (empty($data['errors']) || ! is_array($data['errors'])) {
            return null;
        }

        $first = reset($data['errors']);

        return is_array($first) && isset($first['status_description']) && is_string($first['status_description'])
            ? $first['status_description']
            : null;
    }
}
