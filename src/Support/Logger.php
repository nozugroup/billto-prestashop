<?php

namespace BillTo\PrestaShop\Support;

use PrestaShopLogger;

/**
 * PrestaShop logger wrapper (Advanced Parameters -> Logs).
 */
final class Logger
{
    public function info(string $message): void
    {
        $this->log($message, 1);
    }

    public function warning(string $message): void
    {
        $this->log($message, 2);
    }

    public function error(string $message): void
    {
        $this->log($message, 3);
    }

    private function log(string $message, int $severity): void
    {
        if (class_exists('PrestaShopLogger')) {
            PrestaShopLogger::addLog('[BillTo] ' . $message, $severity, null, 'BilltoInvoices', null, true);
        }
    }
}
