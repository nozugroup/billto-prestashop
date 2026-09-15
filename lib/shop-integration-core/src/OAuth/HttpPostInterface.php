<?php

namespace BillTo\Shop\OAuth;

/**
 * Minimal HTTP POST the core needs (OAuth token and registration endpoints). Plugins implement
 * it with their platform's HTTP layer (wp_remote_post, curl in PrestaShop...), exactly as they
 * already do for VIES.
 *
 * The core stays transport-free on purpose: WordPress hosts and PrestaShop installs each ship
 * their own, version-dependent HTTP stack, and bundling another one is how plugins start
 * fighting over a shared Guzzle.
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
