<?php

namespace BillTo\PrestaShop\Support;

use BillTo\Shop\Settings;
use Configuration;

/**
 * Module settings stored in PrestaShop Configuration (prefix BILLTO_).
 */
final class Config
{
    const PREFIX = 'BILLTO_';

    const ENV_PRODUCTION = 'production';

    const ENV_SANDBOX = 'sandbox';

    const ENV_CUSTOM = 'custom';

    const MODE_ALL = 'all';

    const MODE_VAT_NUMBER = 'vat_number';

    const DELIVERY_BILLTO_EMAIL = 'billto_email';

    const DELIVERY_LINK_ONLY = 'link_only';

    const SYNC_IMMEDIATE = 'immediate';

    const SYNC_CRON = 'cron';

    /**
     * @return array<string, mixed>
     */
    public static function defaults(): array
    {
        return [
            'TOKEN' => '',
            'ENVIRONMENT' => self::ENV_PRODUCTION,
            'CUSTOM_URL' => '',
            'INVOICE_MODE' => self::MODE_ALL,
            'DELIVERY_MODE' => self::DELIVERY_BILLTO_EMAIL,
            'CREATE_ON_CHECKOUT' => 1,
            'PAID_STATES' => '', // comma-separated id_order_state; empty = states flagged "paid" in PrestaShop
            'SETTLE_STATES' => '', // comma-separated; empty = states flagged "delivery"/"shipped"
            'UNPAID_MODULES' => 'ps_cashondelivery,ps_wirepayment',
            'KSEF_AUTO' => 0,
            'REFUND_CORRECTIONS' => 1,
            'AMOUNT_REFUND_MODE' => Settings::REFUND_PROPORTIONAL,
            'AMOUNT_MODE' => Settings::AMOUNT_GROSS,
            'NEGATIVE_LINES' => Settings::NEGATIVE_DISTRIBUTE,
            'VAT_ZERO' => '0 KR',
            'VAT_NO_TAX' => 'zw',
            'OSS_MODE' => Settings::OSS_PL_VAT,
            'EU_B2B_ENABLED' => 1,
            'NON_EU_ENABLED' => 1,
            'VIES_CHECK' => Settings::VIES_BLOCK,
            'EU_CONSUMER_NO_VAT' => Settings::EU_CONSUMER_NO_VAT_BLOCK,
            'UNTAXED_EXTRAS_FOLLOW_GOODS' => 1,
            'SERIES_ID' => '',
            'KOR_SERIES_ID' => '',
            'OSS_SERIES_ID' => '',
            'TAX_ID_FIELD' => 'vat_number', // vat_number | dni
            'SYNC_MODE' => self::SYNC_IMMEDIATE,
            'CRON_TOKEN' => '',
            'RESYNC_ON_EDIT' => 1,
        ];
    }

    public function get(string $key)
    {
        $value = Configuration::get(self::PREFIX . $key);
        $defaults = self::defaults();

        return $value === false || $value === null ? (isset($defaults[$key]) ? $defaults[$key] : null) : $value;
    }

    public function set(string $key, $value): void
    {
        Configuration::updateValue(self::PREFIX . $key, $value);
    }

    public function seedDefaults(): void
    {
        foreach (self::defaults() as $key => $value) {
            if (Configuration::get(self::PREFIX . $key) === false) {
                Configuration::updateValue(self::PREFIX . $key, $key === 'CRON_TOKEN' ? bin2hex(random_bytes(16)) : $value);
            }
        }
    }

    public function token(): string
    {
        return trim((string) $this->get('TOKEN'));
    }

    public function baseUrl(): string
    {
        switch ((string) $this->get('ENVIRONMENT')) {
            case self::ENV_SANDBOX:
                return \BillTo\Shop\ApiPaths::SANDBOX;
            case self::ENV_CUSTOM:
                $url = rtrim((string) $this->get('CUSTOM_URL'), '/');

                return $url !== '' ? $url : \BillTo\Shop\ApiPaths::PRODUCTION;
            default:
                return \BillTo\Shop\ApiPaths::PRODUCTION;
        }
    }

    public function isSandbox(): bool
    {
        return (string) $this->get('ENVIRONMENT') === self::ENV_SANDBOX;
    }

    public function invoicesOnlyWithVatNumber(): bool
    {
        return (string) $this->get('INVOICE_MODE') === self::MODE_VAT_NUMBER;
    }

    public function billtoSendsEmail(): bool
    {
        return (string) $this->get('DELIVERY_MODE') === self::DELIVERY_BILLTO_EMAIL;
    }

    public function createOnCheckout(): bool
    {
        return (bool) $this->get('CREATE_ON_CHECKOUT');
    }

    public function ksefAuto(): bool
    {
        return (bool) $this->get('KSEF_AUTO');
    }

    public function refundCorrections(): bool
    {
        return (bool) $this->get('REFUND_CORRECTIONS');
    }

    public function resyncOnEdit(): bool
    {
        return (bool) $this->get('RESYNC_ON_EDIT');
    }

    public function taxIdField(): string
    {
        return (string) $this->get('TAX_ID_FIELD') === 'dni' ? 'dni' : 'vat_number';
    }

    public function syncImmediately(): bool
    {
        return (string) $this->get('SYNC_MODE') !== self::SYNC_CRON;
    }

    public function cronToken(): string
    {
        return (string) $this->get('CRON_TOKEN');
    }

    /** @return int[] */
    public function paidStates(): array
    {
        return $this->idList((string) $this->get('PAID_STATES'));
    }

    /** @return int[] */
    public function settleStates(): array
    {
        return $this->idList((string) $this->get('SETTLE_STATES'));
    }

    /** @return string[] */
    public function unpaidModules(): array
    {
        return array_values(array_filter(array_map('trim', explode(',', (string) $this->get('UNPAID_MODULES')))));
    }

    /** @return int[] */
    private function idList(string $csv): array
    {
        $ids = [];

        foreach (explode(',', $csv) as $part) {
            $id = (int) trim($part);

            if ($id > 0) {
                $ids[] = $id;
            }
        }

        return $ids;
    }

    public function toSettings(): Settings
    {
        $settings = new Settings();
        $settings->source = 'prestashop';
        $settings->orderNoteLabel = 'Zamówienie PrestaShop';
        $settings->shippingLabel = 'Dostawa';
        $settings->amountMode = (string) $this->get('AMOUNT_MODE') === Settings::AMOUNT_NET ? Settings::AMOUNT_NET : Settings::AMOUNT_GROSS;
        $settings->vatTypeForZeroRate = (string) $this->get('VAT_ZERO');
        $settings->vatTypeForNoTax = (string) $this->get('VAT_NO_TAX');
        $settings->ossMode = (string) $this->get('OSS_MODE');
        $settings->euConsumerNoVat = (string) $this->get('EU_CONSUMER_NO_VAT') === Settings::EU_CONSUMER_NO_VAT_MAP ? Settings::EU_CONSUMER_NO_VAT_MAP : Settings::EU_CONSUMER_NO_VAT_BLOCK;
        $settings->untaxedExtrasFollowGoods = (bool) $this->get('UNTAXED_EXTRAS_FOLLOW_GOODS');
        $settings->euB2bEnabled = (bool) $this->get('EU_B2B_ENABLED');
        $settings->nonEuEnabled = (bool) $this->get('NON_EU_ENABLED');
        $settings->viesCheck = (string) $this->get('VIES_CHECK');
        $settings->negativeLines = (string) $this->get('NEGATIVE_LINES');
        $settings->amountRefundMode = (string) $this->get('AMOUNT_REFUND_MODE');
        $settings->unpaidGateways = $this->unpaidModules();
        $settings->billtoSendsEmail = $this->billtoSendsEmail();
        $settings->seriesId = trim((string) $this->get('SERIES_ID')) !== '' ? trim((string) $this->get('SERIES_ID')) : null;
        $settings->ossSeriesId = trim((string) $this->get('OSS_SERIES_ID')) !== '' ? trim((string) $this->get('OSS_SERIES_ID')) : null;
        $settings->korSeriesId = trim((string) $this->get('KOR_SERIES_ID')) !== '' ? trim((string) $this->get('KOR_SERIES_ID')) : null;

        return $settings;
    }
}
