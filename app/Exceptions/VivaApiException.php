<?php

namespace App\Exceptions;

final class VivaApiException extends VivaException
{
    public function __construct(
        string $message,
        public readonly string $endpoint,
        public readonly int $status,
    ) {
        parent::__construct($message, $status);
    }
}
