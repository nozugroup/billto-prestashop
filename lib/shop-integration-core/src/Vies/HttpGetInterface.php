<?php

namespace BillTo\Shop\Vies;

/**
 * Minimal HTTP GET the core needs (VIES). Plugins implement it with their platform's HTTP layer
 * (wp_remote_get, Guzzle in PrestaShop, curl...).
 */
interface HttpGetInterface
{
    /**
     * @return array{status: int, body: string}|null null on a transport failure
     */
    public function get(string $url): ?array;
}
