<?php

namespace BillTo\Shop;

use BillTo\Shop\Model\Buyer;

/**
 * Classifies a buyer into one of the invoicing scenarios.
 *
 * - PL consumer / PL company: domestic VAT invoice with the shop's Polish rates.
 * - EU consumer (no VAT id, EU country other than PL): OSS invoice, Polish VAT below the
 *   threshold, or no invoice - per settings.
 * - EU company (EU VAT id): goods 0% WDT, services "np I" (art. 28b).
 * - Outside the EU (company or consumer): goods 0% export, services "np II".
 */
final class BuyerScenario
{
    const PL_B2C = 'pl_b2c';

    const PL_B2B = 'pl_b2b';

    const EU_B2C = 'eu_b2c';

    const EU_B2B = 'eu_b2b';

    const NON_EU = 'non_eu';

    /** EU company whose VAT id failed VIES, invoiced with domestic rates (settings fallback). */
    const EU_B2B_DOMESTIC = 'eu_b2b_domestic';

    public static function classify(string $country, bool $hasTaxId): string
    {
        $country = strtoupper(trim($country));

        if ($country === '' || $country === 'PL') {
            return $hasTaxId ? self::PL_B2B : self::PL_B2C;
        }

        if (EuCountries::isEu($country)) {
            return $hasTaxId ? self::EU_B2B : self::EU_B2C;
        }

        return self::NON_EU;
    }

    public static function forBuyer(Buyer $buyer): string
    {
        return self::classify($buyer->country, $buyer->hasTaxId());
    }

    /**
     * Scenario used for rate mapping, taking a failed VIES check into account.
     *
     * @param string|null $viesStatus Vies::VALID|INVALID|UNAVAILABLE or null when not checked
     */
    public static function effective(Buyer $buyer, Settings $settings, ?string $viesStatus): string
    {
        $scenario = self::forBuyer($buyer);

        if ($scenario === self::EU_B2B && $viesStatus === Vies\Vies::INVALID && $settings->viesCheck === Settings::VIES_DOMESTIC) {
            return self::EU_B2B_DOMESTIC;
        }

        return $scenario;
    }

    /** Whether the settings allow invoicing this scenario at all. */
    public static function isEnabled(string $scenario, Settings $settings): bool
    {
        switch ($scenario) {
            case self::EU_B2C:
                return $settings->ossMode !== Settings::OSS_OFF;
            case self::EU_B2B:
            case self::EU_B2B_DOMESTIC:
                return $settings->euB2bEnabled;
            case self::NON_EU:
                return $settings->nonEuEnabled;
            default:
                return true;
        }
    }

    public static function isDomestic(string $scenario): bool
    {
        return $scenario === self::PL_B2C || $scenario === self::PL_B2B;
    }

    /**
     * BillTo buyer tax identity: [tax_type, tax_number|null, tax_country|null].
     *
     * @return array{0: string, 1: string|null, 2: string|null}
     */
    public static function taxIdentity(Buyer $buyer): array
    {
        if (! $buyer->hasTaxId()) {
            return ['none', null, null];
        }

        if ($buyer->country === 'PL') {
            return ['local', Nip::normalize($buyer->taxId), null];
        }

        if (EuCountries::isEu($buyer->country)) {
            return ['eu', Nip::euVatFor($buyer->taxId, $buyer->country), $buyer->country];
        }

        return ['noneu', $buyer->taxId, $buyer->country];
    }
}
