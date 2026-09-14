<?php

declare(strict_types=1);

uses()->beforeEach(function (): void {
    PsRegistry::reset();
})->in('Unit');

/**
 * Registers a PrestaShop-like order with its address, customer, currency and carrier.
 *
 * @param  array<int, array<string, mixed>>  $products  order detail rows
 * @param  array<string, mixed>  $orderOverrides
 * @param  array<string, mixed>  $addressOverrides
 */
function psOrder(array $products, array $orderOverrides = [], array $addressOverrides = []): Order
{
    PsRegistry::put(Currency::class, 1, ['iso_code' => 'PLN']);
    PsRegistry::put(Carrier::class, 3, ['name' => 'Kurier DPD']);
    PsRegistry::put(Country::class, 14, ['iso_code' => 'PL']);
    PsRegistry::put(Country::class, 1, ['iso_code' => 'DE']);
    PsRegistry::put(Country::class, 21, ['iso_code' => 'US']);
    PsRegistry::put(Customer::class, 5, ['email' => 'jan@acme.pl']);
    PsRegistry::put(Address::class, 7, array_merge([
        'id_country' => 14, 'company' => 'ACME sp. z o.o.', 'firstname' => 'Jan', 'lastname' => 'Kowalski',
        'vat_number' => '5261040828', 'dni' => '', 'address1' => 'Testowa 5', 'address2' => '', 'postcode' => '30-001', 'city' => 'Kraków',
        'phone' => '600100200', 'phone_mobile' => '',
    ], $addressOverrides));
    PsRegistry::put(Order::class, 42, array_merge([
        'reference' => 'ABCDEFGHI', 'id_currency' => 1, 'date_add' => '2026-09-14 10:00:00', 'id_address_invoice' => 7, 'id_customer' => 5,
        'module' => 'ps_checkpayment', 'payment' => 'Płatność testowa', 'id_carrier' => 3, 'current_state' => 2, 'secure_key' => 'k',
        'total_shipping_tax_excl' => 9.92, 'total_shipping_tax_incl' => 12.20,
        'total_wrapping_tax_excl' => 0, 'total_wrapping_tax_incl' => 0,
        'total_discounts_tax_excl' => 0, 'total_discounts_tax_incl' => 0,
        'total_paid_tax_incl' => 110.60,
        'products' => $products,
    ], $orderOverrides));

    return new Order(42);
}

/**
 * @return array<string, mixed>
 */
function psProduct(int $detailId, string $name, float $qty, float $totalExcl, float $totalIncl, ?float $taxRate, string $reference = '', bool $virtual = false): array
{
    return [
        'id_order_detail' => $detailId, 'product_name' => $name, 'product_quantity' => $qty, 'product_reference' => $reference,
        'total_price_tax_excl' => $totalExcl, 'total_price_tax_incl' => $totalIncl, 'tax_rate' => $taxRate, 'is_virtual' => $virtual ? 1 : 0,
    ];
}
