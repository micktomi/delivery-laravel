<?php

namespace App\Exceptions;

final class VivaTransportException extends VivaException
{
    public function __construct(string $message, public readonly string $endpoint)
    {
        parent::__construct($message);
    }
}
