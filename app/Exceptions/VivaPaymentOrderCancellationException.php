<?php

namespace App\Exceptions;

use RuntimeException;
use Throwable;

/**
 * Viva did not confirm that a standing payment order is no longer payable.
 * The reason is a short machine code (never provider text or customer data)
 * so the caller can pick the operator message and leave the local order as
 * it is.
 */
class VivaPaymentOrderCancellationException extends RuntimeException
{
    public function __construct(
        public readonly string $reason,
        ?Throwable $previous = null,
    ) {
        parent::__construct('Viva payment order cancellation failed: '.$reason, 0, $previous);
    }
}
