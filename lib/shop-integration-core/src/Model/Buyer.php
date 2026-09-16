<?php

namespace BillTo\Shop\Model;

/**
 * Buyer of a shop order, as entered at checkout. Platform-independent.
 */
final class Buyer
{
    /** @var string */
    public $name;

    /** @var string ISO 3166-1 alpha-2 billing country */
    public $country;

    /** @var string Raw tax id as typed by the customer (NIP or EU VAT id), empty when none */
    public $taxId;

    /** @var string */
    public $addressLine1;

    /** @var string */
    public $addressLine2;

    /** @var string */
    public $email;

    /** @var string */
    public $phone;

    public function __construct(
        string $name,
        string $country,
        string $taxId = '',
        string $addressLine1 = '',
        string $addressLine2 = '',
        string $email = '',
        string $phone = ''
    ) {
        $this->name = trim($name);
        $this->country = strtoupper(trim($country)) !== '' ? strtoupper(trim($country)) : 'PL';
        $this->taxId = trim($taxId);
        $this->addressLine1 = trim($addressLine1);
        $this->addressLine2 = trim($addressLine2);
        $this->email = trim($email);
        $this->phone = trim($phone);
    }

    public function hasTaxId(): bool
    {
        return $this->taxId !== '';
    }
}
