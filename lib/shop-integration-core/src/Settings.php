<?php

namespace BillTo\Shop;

/**
 * Integration settings the core needs to make its decisions. Plugins fill it from their own
 * settings storage. Every value has a safe default so a plugin may set only what it exposes.
 */
final class Settings
{
    const AMOUNT_NET = 'net';

    const AMOUNT_GROSS = 'gross';

    const OSS_OFF = 'off';

    const OSS_PL_VAT = 'pl_vat';

    const OSS_INVOICE = 'oss';

    const VIES_OFF = 'off';

    const VIES_BLOCK = 'block';

    const VIES_DOMESTIC = 'domestic';

    const NEGATIVE_DISTRIBUTE = 'distribute';

    const NEGATIVE_SKIP = 'skip';

    const REFUND_PROPORTIONAL = 'proportional';

    const REFUND_NOTE = 'note';

    /** @var string net|gross */
    public $amountMode = self::AMOUNT_GROSS;

    /** @var string BillTo vat_type for a 0% shop rate on domestic sales */
    public $vatTypeForZeroRate = '0 KR';

    /** @var string BillTo vat_type when the shop applied no tax at all */
    public $vatTypeForNoTax = 'zw';

    /** @var string off|pl_vat|oss */
    public $ossMode = self::OSS_PL_VAT;

    /** @var bool */
    public $euB2bEnabled = true;

    /** @var bool */
    public $nonEuEnabled = true;

    /** @var string off|block|domestic */
    public $viesCheck = self::VIES_BLOCK;

    /** @var string distribute|skip */
    public $negativeLines = self::NEGATIVE_DISTRIBUTE;

    /** @var string proportional|note */
    public $amountRefundMode = self::REFUND_PROPORTIONAL;

    /** @var string[] Gateway ids invoiced as unpaid */
    public $unpaidGateways = [];

    /** @var bool BillTo e-mails the invoice / correction to the buyer */
    public $billtoSendsEmail = true;

    /** @var string|null VAT series id, null = team default */
    public $seriesId = null;

    /** @var string|null OSS series id */
    public $ossSeriesId = null;

    /** @var string|null KOR series id */
    public $korSeriesId = null;

    /** @var string Source tag sent to BillTo (e.g. woocommerce, prestashop) */
    public $source = 'shop';

    /** @var string Label prefix for notes, e.g. "Zamówienie WooCommerce" */
    public $orderNoteLabel = 'Zamówienie';

    /** @var string Shipping line name prefix */
    public $shippingLabel = 'Dostawa';

    /** @var string Suffix added to product lines that absorbed a discount */
    public $discountSuffix = '(z rabatem)';

    /**
     * Optional mapper for tax percentages that are not Polish rates (e.g. 19 on a domestic-mode order).
     * Signature: function(float $percent): ?string. Null result = nearest Polish rate.
     *
     * @var callable|null
     */
    public $unknownRateMapper = null;

    public function isGross(): bool
    {
        return $this->amountMode === self::AMOUNT_GROSS;
    }

    public function isUnpaidGateway(string $gatewayId): bool
    {
        return $gatewayId !== '' && in_array($gatewayId, $this->unpaidGateways, true);
    }
}
