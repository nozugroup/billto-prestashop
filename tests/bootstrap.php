<?php

declare(strict_types=1);

require __DIR__ . '/../vendor/autoload.php';

// Minimal PrestaShop stand-ins so the adapter and config can be exercised without a shop.
require __DIR__ . '/Stubs/prestashop.php';

if (!defined('_PS_VERSION_')) {
    define('_PS_VERSION_', '8.2.0');
}

if (!defined('_COOKIE_KEY_')) {
    define('_COOKIE_KEY_', 'test-cookie-key');
}
