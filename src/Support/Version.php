<?php

namespace BillTo\PrestaShop\Support;

/**
 * Single source of truth for the module version inside PHP.
 *
 * PrestaShop reads the version from `config.xml` and from `$this->version`; the release workflow
 * refuses to publish when the three disagree. The User-Agent and the update check read it here.
 */
final class Version
{
    const MODULE = '1.0.0';
}
