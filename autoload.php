<?php
/**
 * Minimal PSR-4 autoloader for the module's own classes and the vendored shared core.
 * No Composer at runtime: PrestaShop ships its own vendor/ and modules must not clash with it.
 */

spl_autoload_register(static function ($class) {
    $roots = [
        'BillTo\\PrestaShop\\' => __DIR__ . '/src/',
        'BillTo\\Shop\\' => __DIR__ . '/lib/shop-integration-core/src/',
    ];

    foreach ($roots as $prefix => $dir) {
        if (strncmp($class, $prefix, strlen($prefix)) !== 0) {
            continue;
        }

        $file = $dir . str_replace('\\', '/', substr($class, strlen($prefix))) . '.php';

        if (is_file($file)) {
            require $file;
        }

        return;
    }
});
