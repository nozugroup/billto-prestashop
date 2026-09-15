<?php

namespace BillTo\PrestaShop\Support;

/**
 * Single source of truth for the module version inside PHP.
 *
 * PrestaShop reads the version from `config.xml` and from `$this->version`, so the number lives in
 * three places by design; the release workflow refuses to publish when they disagree. Everything
 * else (User-Agent, update check) reads it from here instead of repeating a literal - a stale
 * literal in the User-Agent would quietly report the wrong version in BillTo's telemetry.
 */
final class Version
{
    const MODULE = '0.1.0';
}
