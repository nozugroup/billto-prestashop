<?php

namespace BillTo\Shop;

final class EuCountries
{
    const CODES = ['AT', 'BE', 'BG', 'HR', 'CY', 'CZ', 'DK', 'EE', 'FI', 'FR', 'DE', 'GR', 'HU', 'IE', 'IT', 'LV', 'LT', 'LU', 'MT', 'NL', 'PL', 'PT', 'RO', 'SK', 'SI', 'ES', 'SE'];

    public static function isEu(string $code): bool
    {
        return in_array(strtoupper($code), self::CODES, true);
    }
}
