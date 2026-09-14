<?php

namespace BillTo\PrestaShop\Install;

use BillTo\PrestaShop\Support\Config;
use BillTo\PrestaShop\Support\OrderRecord;
use BillTo\PrestaShop\Sync\Queue;
use Db;
use Language;
use Tab;

/**
 * Database tables, configuration defaults and the hidden admin tab for the action controller.
 */
final class Installer
{
    /** @var \Module */
    private $module;

    public function __construct(\Module $module)
    {
        $this->module = $module;
    }

    public function install(): bool
    {
        $prefix = _DB_PREFIX_;
        $engine = _MYSQL_ENGINE_;

        $sql = [
            "CREATE TABLE IF NOT EXISTS `{$prefix}" . OrderRecord::TABLE . "` (
                `id_order` INT UNSIGNED NOT NULL,
                `billto_order_id` VARCHAR(64) NOT NULL DEFAULT '',
                `billto_order_number` VARCHAR(64) NOT NULL DEFAULT '',
                `line_map` TEXT NULL,
                `invoice_id` VARCHAR(64) NOT NULL DEFAULT '',
                `invoice_number` VARCHAR(64) NOT NULL DEFAULT '',
                `invoice_paid` TINYINT(1) NULL,
                `public_url` VARCHAR(255) NOT NULL DEFAULT '',
                `pdf_path` VARCHAR(255) NOT NULL DEFAULT '',
                `ksef_status` VARCHAR(16) NOT NULL DEFAULT '',
                `ksef_number` VARCHAR(64) NOT NULL DEFAULT '',
                `vies` TEXT NULL,
                `last_error` VARCHAR(1000) NOT NULL DEFAULT '',
                `corrections` TEXT NULL,
                `scenario` VARCHAR(32) NOT NULL DEFAULT '',
                `date_add` DATETIME NOT NULL,
                `date_upd` DATETIME NOT NULL,
                PRIMARY KEY (`id_order`)
            ) ENGINE={$engine} DEFAULT CHARSET=utf8mb4",
            "CREATE TABLE IF NOT EXISTS `{$prefix}" . Queue::TABLE . "` (
                `id_job` INT UNSIGNED NOT NULL AUTO_INCREMENT,
                `job` VARCHAR(32) NOT NULL,
                `id_order` INT UNSIGNED NOT NULL,
                `payload` TEXT NULL,
                `attempt` TINYINT UNSIGNED NOT NULL DEFAULT 0,
                `status` VARCHAR(16) NOT NULL DEFAULT 'pending',
                `error` VARCHAR(500) NULL,
                `run_at` DATETIME NOT NULL,
                `date_add` DATETIME NOT NULL,
                `date_upd` DATETIME NULL,
                PRIMARY KEY (`id_job`),
                KEY `status_run_at` (`status`, `run_at`)
            ) ENGINE={$engine} DEFAULT CHARSET=utf8mb4",
        ];

        foreach ($sql as $statement) {
            if (!Db::getInstance()->execute($statement)) {
                return false;
            }
        }

        (new Config())->seedDefaults();

        return $this->installTab();
    }

    public function uninstall(): bool
    {
        $prefix = _DB_PREFIX_;
        Db::getInstance()->execute("DROP TABLE IF EXISTS `{$prefix}" . Queue::TABLE . '`');
        Db::getInstance()->execute("DROP TABLE IF EXISTS `{$prefix}" . OrderRecord::TABLE . '`');

        foreach (array_keys(Config::defaults()) as $key) {
            \Configuration::deleteByName(Config::PREFIX . $key);
        }

        $idTab = (int) Tab::getIdFromClassName('AdminBilltoInvoices');

        if ($idTab > 0) {
            $tab = new Tab($idTab);
            $tab->delete();
        }

        return true;
    }

    /** Hidden admin tab so the legacy action controller has a route. */
    private function installTab(): bool
    {
        if ((int) Tab::getIdFromClassName('AdminBilltoInvoices') > 0) {
            return true;
        }

        $tab = new Tab();
        $tab->class_name = 'AdminBilltoInvoices';
        $tab->module = $this->module->name;
        $tab->id_parent = -1;
        $tab->active = 1;

        foreach (Language::getLanguages(false) as $language) {
            $tab->name[(int) $language['id_lang']] = 'BillTo';
        }

        return (bool) $tab->add();
    }
}
