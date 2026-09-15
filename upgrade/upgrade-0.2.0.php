<?php
/**
 * Upgrade to 0.2.0.
 *
 * PrestaShop runs this file when the installed version is OLDER than 0.2.0 and the uploaded ZIP
 * declares 0.2.0 or newer. `install()` is not called on that path, so every schema change has to
 * come through here - hence `syncSchema()` (idempotent CREATE/ALTER) instead of a hand-written
 * list of ALTER statements.
 *
 * A new release that changes the schema needs its own `upgrade-X.Y.Z.php` with the same body.
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
function upgrade_module_0_2_0($module)
{
    require_once dirname(__DIR__) . '/autoload.php';

    // Defaults for new settings. `seedDefaults()` only writes keys that are missing, so the
    // shop's own choices are left alone.
    (new Config())->seedDefaults();

    // The cached "latest version" answer describes the version from before this upgrade.
    (new UpdateChecker())->forget();

    return (new Installer($module))->syncSchema();
}
