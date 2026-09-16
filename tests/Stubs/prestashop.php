<?php

declare(strict_types=1);

/**
 * Just enough of PrestaShop's core classes for the unit tests. Objects are filled from a static
 * registry keyed by class + id, so a test can "create" an order, an address, a customer...
 */
final class PsRegistry
{
    /** @var array<string, array<int, array<string, mixed>>> */
    public static array $rows = [];

    /** @var array<string, mixed> */
    public static array $config = [];

    public static function reset(): void
    {
        self::$rows = [];
        self::$config = [];
    }

    public static function put(string $class, int $id, array $attributes): void
    {
        self::$rows[$class][$id] = $attributes;
    }

    public static function fill(object $object, string $class, int $id): void
    {
        foreach (self::$rows[$class][$id] ?? [] as $key => $value) {
            $object->$key = $value;
        }

        $object->id = isset(self::$rows[$class][$id]) ? $id : 0;
    }
}

class Configuration
{
    public static function get($key)
    {
        return PsRegistry::$config[$key] ?? false;
    }

    public static function updateValue($key, $value): bool
    {
        PsRegistry::$config[$key] = $value;

        return true;
    }

    public static function deleteByName($key): bool
    {
        unset(PsRegistry::$config[$key]);

        return true;
    }
}

class Order
{
    public $id = 0;
    public $reference = '';
    public $id_currency = 0;
    public $date_add = '';
    public $id_address_invoice = 0;
    public $id_customer = 0;
    public $module = '';
    public $payment = '';
    public $id_carrier = 0;
    public $current_state = 0;
    public $secure_key = '';
    public $total_shipping_tax_excl = 0;
    public $total_shipping_tax_incl = 0;
    public $total_wrapping_tax_excl = 0;
    public $total_wrapping_tax_incl = 0;
    public $total_discounts_tax_excl = 0;
    public $total_discounts_tax_incl = 0;
    public $total_paid_tax_incl = 0;
    /** @var array<int, array<string, mixed>> */
    public $products = [];

    public function __construct($id = null)
    {
        if ($id !== null) {
            PsRegistry::fill($this, self::class, (int) $id);
        }
    }

    public function getProducts(): array
    {
        return $this->products;
    }
}

class Address
{
    public $id = 0;
    public $id_country = 0;
    public $company = '';
    public $firstname = '';
    public $lastname = '';
    public $vat_number = '';
    public $dni = '';
    public $address1 = '';
    public $address2 = '';
    public $postcode = '';
    public $city = '';
    public $phone = '';
    public $phone_mobile = '';

    public function __construct($id = null)
    {
        if ($id !== null) {
            PsRegistry::fill($this, self::class, (int) $id);
        }
    }
}

class Customer
{
    public $id = 0;
    public $email = '';

    public function __construct($id = null)
    {
        if ($id !== null) {
            PsRegistry::fill($this, self::class, (int) $id);
        }
    }
}

class Currency
{
    public $id = 0;
    public $iso_code = 'PLN';

    public function __construct($id = null)
    {
        if ($id !== null) {
            PsRegistry::fill($this, self::class, (int) $id);
        }
    }
}

class Carrier
{
    public $id = 0;
    public $name = '';

    public function __construct($id = null)
    {
        if ($id !== null) {
            PsRegistry::fill($this, self::class, (int) $id);
        }
    }
}

class Country
{
    public static function getIsoById($id): string
    {
        return PsRegistry::$rows[self::class][(int) $id]['iso_code'] ?? '';
    }
}
