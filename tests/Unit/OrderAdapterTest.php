<?php

declare(strict_types=1);

use BillTo\PrestaShop\Support\Config;
use BillTo\PrestaShop\Sync\OrderAdapter;
use BillTo\Shop\BuyerScenario;
use BillTo\Shop\MarkPaidPayload;
use BillTo\Shop\Model\Line;
use BillTo\Shop\OrderPayloadBuilder;

it('maps a PrestaShop order onto the core model', function () {
    $order = psOrder([psProduct(101, 'Kubek', 2, 80.0, 98.40, 23.0, 'KUB-001')]);
    $core = (new OrderAdapter(new Config()))->toCoreOrder($order);

    expect($core->externalId)->toBe('42')
        ->and($core->number)->toBe('ABCDEFGHI')
        ->and($core->currency)->toBe('PLN')
        ->and($core->date)->toBe('2026-09-14')
        ->and($core->paymentGatewayId)->toBe('ps_checkpayment')
        ->and($core->buyer->name)->toBe('ACME sp. z o.o.')
        ->and($core->buyer->country)->toBe('PL')
        ->and($core->buyer->taxId)->toBe('5261040828')
        ->and($core->buyer->email)->toBe('jan@acme.pl')
        ->and($core->buyer->addressLine2)->toBe('30-001 Kraków')
        ->and($core->lines)->toHaveCount(2)
        ->and($core->lines[0]->id)->toBe('101')
        ->and($core->lines[0]->name)->toBe('Kubek [KUB-001]')
        ->and($core->lines[0]->netTotal)->toBe(80.0)
        ->and($core->lines[0]->grossTotal)->toBe(98.40)
        ->and($core->lines[0]->taxPercent)->toBe(23.0)
        ->and($core->lines[1]->type)->toBe(Line::TYPE_SHIPPING)
        ->and($core->lines[1]->id)->toBe('shipping')
        ->and($core->lines[1]->name)->toBe('Kurier DPD')
        ->and($core->lines[1]->taxPercent)->toBe(22.98); // derived from 9.92 -> 12.20
});

it('builds the BillTo order payload through the core with gross amounts by default', function () {
    $order = psOrder([psProduct(101, 'Kubek', 2, 80.0, 98.40, 23.0)]);
    $config = new Config();
    $built = (new OrderPayloadBuilder($config->toSettings()))->build((new OrderAdapter($config))->toCoreOrder($order));

    expect($built['scenario'])->toBe(BuyerScenario::PL_B2B)
        ->and($built['payload']['source'])->toBe('prestashop')
        ->and($built['payload']['amount_entry_mode'])->toBe('gross')
        ->and($built['payload']['items'][0])->toMatchArray(['unit_price' => 40.0, 'unit_price_gross' => 49.2, 'vat_type' => '23'])
        ->and($built['payload']['items'][1])->toMatchArray(['name' => 'Dostawa: Kurier DPD', 'unit_price_gross' => 12.2, 'vat_type' => '23'])
        ->and($built['lineMap'])->toBe(['101' => 1, 'shipping' => 2])
        ->and($built['payload']['notes'])->toContain('Zamówienie PrestaShop #ABCDEFGHI');
});

it('turns cart-rule discounts into a distributed negative line and wrapping into a fee', function () {
    $order = psOrder(
        [psProduct(101, 'A', 2, 100.0, 123.0, 23.0), psProduct(102, 'B', 1, 100.0, 123.0, 23.0)],
        ['total_discounts_tax_excl' => 30.0, 'total_discounts_tax_incl' => 36.9, 'total_wrapping_tax_excl' => 5.0, 'total_wrapping_tax_incl' => 6.15],
    );
    $config = new Config();
    $core = (new OrderAdapter($config))->toCoreOrder($order);

    expect(array_map(static fn (Line $l) => $l->id, $core->lines))->toBe(['101', '102', 'shipping', 'wrapping', 'discount'])
        ->and($core->lines[4]->netTotal)->toBe(-30.0);

    $built = (new OrderPayloadBuilder($config->toSettings()))->build($core);
    $items = $built['payload']['items'];

    expect($built['hasNegativeLines'])->toBeTrue()
        ->and($items)->toHaveCount(4)
        ->and($items[0]['name'])->toBe('A (z rabatem)')
        ->and($items[0]['unit_price'])->toBe(42.5)
        ->and($items[2]['name'])->toBe('Dostawa: Kurier DPD')
        ->and($items[3]['name'])->toBe('Pakowanie na prezent');
});

it('recognises consumers, EU companies and virtual products as services', function () {
    $consumer = psOrder([psProduct(101, 'Ebook', 1, 100.0, 105.0, 5.0, '', true)], [], ['company' => '', 'vat_number' => '']);
    $core = (new OrderAdapter(new Config()))->toCoreOrder($consumer);
    expect($core->buyer->name)->toBe('Jan Kowalski')->and($core->buyer->hasTaxId())->toBeFalse()->and($core->lines[0]->isService)->toBeTrue();

    $gmbh = psOrder([psProduct(101, 'Widget', 1, 100.0, 100.0, 0.0), psProduct(102, 'Consulting', 1, 100.0, 100.0, 0.0, '', true)], ['total_shipping_tax_excl' => 20.0, 'total_shipping_tax_incl' => 20.0], ['id_country' => 1, 'company' => 'Beispiel GmbH', 'vat_number' => 'DE123456789']);
    $config = new Config();
    $built = (new OrderPayloadBuilder($config->toSettings()))->build((new OrderAdapter($config))->toCoreOrder($gmbh));
    expect($built['scenario'])->toBe(BuyerScenario::EU_B2B)
        ->and(array_column($built['payload']['items'], 'vat_type'))->toBe(['0 WDT', 'np I', '0 WDT'])
        ->and($built['payload']['buyer'])->toMatchArray(['tax_type' => 'eu', 'tax_number' => 'DE123456789', 'tax_country' => 'DE']);
});

it('reads the tax id from dni when configured and builds mark-paid flags for deferred payment modules', function () {
    Configuration::updateValue('BILLTO_TAX_ID_FIELD', 'dni');
    Configuration::updateValue('BILLTO_DELIVERY_MODE', Config::DELIVERY_LINK_ONLY);
    Configuration::updateValue('BILLTO_SERIES_ID', 'ser-1');

    $cod = psOrder([psProduct(101, 'A', 1, 100.0, 123.0, 23.0)], ['module' => 'ps_cashondelivery'], ['vat_number' => '', 'dni' => '526-104-08-28']);
    $config = new Config();
    $core = (new OrderAdapter($config))->toCoreOrder($cod);

    expect($core->buyer->taxId)->toBe('526-104-08-28')
        ->and($config->unpaidModules())->toBe(['ps_cashondelivery', 'ps_wirepayment'])
        ->and(MarkPaidPayload::build($core, $config->toSettings()))->toBe(['send_email' => false, 'mark_paid' => false, 'series_id' => 'ser-1']);
});

it('parses configured state and module lists', function () {
    Configuration::updateValue('BILLTO_PAID_STATES', '2, 4,x,0');
    Configuration::updateValue('BILLTO_UNPAID_MODULES', ' ps_cashondelivery ,, ps_wirepayment');
    Configuration::updateValue('BILLTO_ENVIRONMENT', Config::ENV_SANDBOX);

    $config = new Config();

    expect($config->paidStates())->toBe([2, 4])
        ->and($config->unpaidModules())->toBe(['ps_cashondelivery', 'ps_wirepayment'])
        ->and($config->baseUrl())->toBe('https://sandbox.billto.pl/api/v1')
        ->and($config->isSandbox())->toBeTrue()
        ->and($config->toSettings()->source)->toBe('prestashop');
});
