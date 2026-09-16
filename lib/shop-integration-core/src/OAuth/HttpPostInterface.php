<?php

namespace BillTo\Shop\OAuth;

/**
 * Minimal HTTP POST the core needs (OAuth token and registration endpoints). Plugins implement
 * it with their platform's HTTP layer - `wp_remote_post` in WordPress, curl in PrestaShop -
 * so the core ships no transport of its own.
 */
interface HttpPostInterface
{
    /**
     * @param  array<string, string>  $fields  form fields (application/x-www-form-urlencoded)
     * @param  array<string, string>  $headers
     * @return array{status: int, body: string}|null null on a transport failure
     */
    public function post(string $url, array $fields, array $headers = []): ?array;
}
