<?php

namespace BillTo\PrestaShop\Support;

use BillTo\PrestaShop\Api\Client;
use Configuration;

/**
 * Update channel for a module distributed outside PrestaShop Addons.
 *
 * PrestaShop only knows how to update modules bought on Addons, so a module installed from a ZIP
 * never tells its owner that a newer version exists. The shop asks BillTo
 * (`/api/v1/plugins/prestashop/latest`, which relays the latest published GitHub release) and the
 * module configuration page shows the answer. Installing the update is still a manual ZIP upload -
 * PrestaShop gives a module no supported way to replace its own files.
 *
 * The answer is cached in Configuration for 12 h; a failed lookup is cached as well. The lookup
 * always goes to production billto.pl, also when the shop talks to the sandbox API.
 */
class UpdateChecker
{
    const ENDPOINT = 'https://billto.pl/api/v1/plugins/prestashop/latest';

    const CACHE_KEY = 'BILLTO_UPDATE_CACHE';

    /** 12 hours. */
    const CACHE_TTL = 43200;

    const TIMEOUT = 10;

    /**
     * The release payload, or null when nothing is published or BillTo is unreachable.
     *
     * @return array<string, mixed>|null
     */
    public function latest()
    {
        $cached = $this->readCache();

        if ($cached !== null) {
            return isset($cached['payload']) && is_array($cached['payload']) ? $cached['payload'] : null;
        }

        $payload = $this->fetch();

        $this->writeCache($payload);

        return $payload;
    }

    /**
     * The release payload when it is newer than the running module and carries an installable ZIP.
     *
     * @param string|null $currentVersion
     *
     * @return array<string, mixed>|null
     */
    public function availableUpdate($currentVersion = null)
    {
        $currentVersion = $currentVersion === null ? Version::MODULE : (string) $currentVersion;
        $payload = $this->latest();

        // A release with no attached ZIP is a broken release: pointing the shop owner at a file
        // they cannot install is worse than saying nothing.
        if (!is_array($payload) || empty($payload['version']) || empty($payload['download_url'])) {
            return null;
        }

        if (version_compare((string) $payload['version'], $currentVersion, '<=')) {
            return null;
        }

        return $payload;
    }

    /** Drops the cached answer so the next check asks BillTo again. */
    public function forget()
    {
        Configuration::deleteByName(self::CACHE_KEY);
    }

    /**
     * @return array<string, mixed>|null
     */
    private function readCache()
    {
        $raw = Configuration::get(self::CACHE_KEY);

        if (!is_string($raw) || $raw === '') {
            return null;
        }

        $decoded = json_decode($raw, true);

        if (!is_array($decoded) || !isset($decoded['fetched_at'])) {
            return null;
        }

        if ((int) $decoded['fetched_at'] + self::CACHE_TTL < time()) {
            return null;
        }

        return $decoded;
    }

    /**
     * @param array<string, mixed>|null $payload
     *
     * @return void
     */
    private function writeCache($payload)
    {
        Configuration::updateValue(self::CACHE_KEY, json_encode([
            'fetched_at' => time(),
            'payload' => $payload,
        ]));
    }

    /**
     * @return array<string, mixed>|null
     */
    protected function fetch()
    {
        $ch = curl_init(self::ENDPOINT);

        if ($ch === false) {
            return null;
        }

        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => self::TIMEOUT,
            CURLOPT_HTTPHEADER => [
                'Accept: application/json',
                'User-Agent: ' . Client::userAgent(),
            ],
        ]);

        $body = curl_exec($ch);
        $status = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
        curl_close($ch);

        if (!is_string($body) || $status !== 200) {
            return null;
        }

        $decoded = json_decode($body, true);

        if (!is_array($decoded) || empty($decoded['version'])) {
            return null;
        }

        return $decoded;
    }
}
