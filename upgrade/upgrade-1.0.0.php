<?php
/**
 * Upgrade to 1.0.0.
 *
 * PrestaShop runs this file when the installed version is older than 1.0.0 and the uploaded ZIP
 * declares 1.0.0 or newer. `install()` is not called on that path, so the schema is brought up to
 * date by `syncSchema()`.
 *
 * A release that changes the schema needs its own `upgrade-X.Y.Z.php` with the same body.
 *
 * @author    BillTo
 * @copyright 2026 NOZU sp. z o.o.
 * @license   https://opensource.org/licenses/AFL-3.0 Academic Free License 3.0
 */

if (!defined('_PS_VERSION_')) {
    exit;
}

use BillTo\PrestaShop\Install\Installer;
use BillTo\PrestaShop\Support\Config;
use BillTo\PrestaShop\Support\UpdateChecker;

/**
 * @param \Module $module
 *
 * @return bool
 */
function upgrade_module_1_0_0($module)
{
    require_once dirname(__DIR__) . '/autoload.php';

    // Defaults for new settings. `seedDefaults()` only writes keys that are missing, so the
    // shop's own choices are left alone.
    (new Config())->seedDefaults();

    // The cached "latest version" answer describes the version from before this upgrade.
    (new UpdateChecker())->forget();

    return (new Installer($module))->syncSchema();
}
