<?php

declare(strict_types=1);

use BillTo\PrestaShop\Api\Client;
use BillTo\PrestaShop\Support\Version;

it('identifies the module, not the PrestaShop runtime', function () {
    // The product segment must name THIS module: BillTo uses it to reach the vendor before a
    // breaking change, and "PrestaShop/1.7.8" is shared by unrelated integrations.
    expect(Client::userAgent())->toStartWith('billto-prestashop/' . Client::MODULE_VERSION . ' (+');
});

it('carries a contact and a compat declaration', function () {
    // Without a contact the header names a product nobody can be notified about - the API
    // treats such a header as unidentified.
    expect(Client::userAgent())
        ->toContain('(+https://billto.pl/integracje/prestashop')
        ->toContain('compat=' . Client::API_COMPAT);
});

it('appends the platform version after the metadata group', function () {
    $header = Client::userAgent();

    expect($header)->toContain('PrestaShop/')
        ->and(strpos($header, 'PrestaShop/'))->toBeGreaterThan(strpos($header, ')'));
});

it('keeps the metadata group well formed', function () {
    $header = Client::userAgent();

    expect(substr_count($header, '('))->toBe(1)
        ->and(substr_count($header, ')'))->toBe(1);
});

it('reports the same version the module declares', function () {
    // The API groups installations by this value, so a release that bumps one and forgets the
    // other looks like the previous release and would be missed by change notifications.
    $config = file_get_contents(__DIR__ . '/../../config.xml');

    preg_match('/<version><!\[CDATA\[([^\]]+)\]\]><\/version>/', (string) $config, $matches);

    expect($matches[1] ?? null)->toBe(Client::MODULE_VERSION)
        ->and(Client::MODULE_VERSION)->toBe(Version::MODULE);
});
