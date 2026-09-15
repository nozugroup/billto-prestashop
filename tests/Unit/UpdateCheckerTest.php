<?php

declare(strict_types=1);

use BillTo\PrestaShop\Support\UpdateChecker;

/**
 * Checker with a stubbed transport that counts calls, so the cache can be asserted.
 */
final class FakeUpdateChecker extends UpdateChecker
{
    /** @var array<string, mixed>|null */
    public $response;

    /** @var int */
    public $calls = 0;

    /**
     * @param array<string, mixed>|null $response
     */
    public function __construct($response)
    {
        $this->response = $response;
    }

    protected function fetch()
    {
        ++$this->calls;

        return $this->response;
    }
}

/**
 * @param array<string, mixed> $overrides
 *
 * @return array<string, mixed>
 */
function psRelease(array $overrides = []): array
{
    return array_merge([
        'platform' => 'prestashop',
        'slug' => 'billtoinvoices',
        'version' => '0.9.0',
        'download_url' => 'https://github.com/nozugroup/billto-prestashop/releases/download/v0.9.0/billtoinvoices.zip',
        'changelog_url' => 'https://github.com/nozugroup/billto-prestashop/releases/tag/v0.9.0',
    ], $overrides);
}

it('reports an update when BillTo publishes a newer version', function () {
    $checker = new FakeUpdateChecker(psRelease());

    $update = $checker->availableUpdate('0.1.0');

    expect($update)->toBeArray()
        ->and($update['version'])->toBe('0.9.0')
        ->and($update['download_url'])->toContain('billtoinvoices.zip');
});

it('stays quiet when the installed version is current', function () {
    $checker = new FakeUpdateChecker(psRelease(['version' => '0.1.0']));

    expect($checker->availableUpdate('0.1.0'))->toBeNull();
});

it('stays quiet when the release carries no installable ZIP', function () {
    // A release without an attached archive is a broken release - sending the shop owner after a
    // file they cannot install is worse than saying nothing.
    $checker = new FakeUpdateChecker(psRelease(['download_url' => null]));

    expect($checker->availableUpdate('0.1.0'))->toBeNull();
});

it('stays quiet when BillTo is unreachable', function () {
    $checker = new FakeUpdateChecker(null);

    expect($checker->availableUpdate('0.1.0'))->toBeNull();
});

it('asks once and serves the cache on the next visit to the configuration page', function () {
    $checker = new FakeUpdateChecker(psRelease());

    $checker->availableUpdate('0.1.0');
    $checker->availableUpdate('0.1.0');

    expect($checker->calls)->toBe(1);
});

it('does not retry immediately after a failed lookup', function () {
    $checker = new FakeUpdateChecker(null);

    $checker->availableUpdate('0.1.0');
    $checker->availableUpdate('0.1.0');

    expect($checker->calls)->toBe(1);
});

it('asks again once the cache is dropped', function () {
    $checker = new FakeUpdateChecker(psRelease());

    $checker->availableUpdate('0.1.0');
    $checker->forget();
    $checker->availableUpdate('0.1.0');

    expect($checker->calls)->toBe(2);
});
