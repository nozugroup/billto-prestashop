<?php

namespace BillTo\Shop;

/**
 * Polish tax identification number (NIP) and EU VAT id helpers.
 */
final class Nip
{
    const WEIGHTS = [6, 5, 7, 2, 3, 4, 5, 6, 7];

    /** Digits only, with an optional "PL" prefix removed. */
    public static function normalize(string $value): string
    {
        $value = strtoupper(trim($value));

        if (strpos($value, 'PL') === 0) {
            $value = substr($value, 2);
        }

        $digits = preg_replace('/\D+/', '', $value);

        return $digits === null ? '' : $digits;
    }

    /** Checksum validation of a 10-digit NIP. */
    public static function isValid(string $value): bool
    {
        $digits = self::normalize($value);

        if (strlen($digits) !== 10 || $digits === '0000000000') {
            return false;
        }

        $sum = 0;

        foreach (self::WEIGHTS as $index => $weight) {
            $sum += $weight * (int) $digits[$index];
        }

        return ($sum % 11) === (int) $digits[9];
    }

    /**
     * EU VAT identifier as "CC" + national part, or null when it does not look like one.
     */
    public static function normalizeEuVat(string $value): ?string
    {
        $value = strtoupper((string) preg_replace('/[\s\-\.]/', '', $value));

        return preg_match('/^[A-Z]{2}[A-Z0-9]{2,12}$/', $value) === 1 ? $value : null;
    }

    /**
     * EU VAT id for a buyer: accepts "DE123", "123" (country prepended) or anything else as-is.
     */
    public static function euVatFor(string $value, string $country): string
    {
        $normalized = self::normalizeEuVat($value);

        if ($normalized !== null) {
            return $normalized;
        }

        $withCountry = self::normalizeEuVat(strtoupper($country).$value);

        return $withCountry !== null ? $withCountry : trim($value);
    }
}
