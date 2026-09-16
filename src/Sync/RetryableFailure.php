<?php

namespace BillTo\PrestaShop\Sync;

/**
 * Transient failure (network, 5xx, throttling, VIES down): the job is re-queued with backoff.
 */
final class RetryableFailure extends \RuntimeException
{
}
