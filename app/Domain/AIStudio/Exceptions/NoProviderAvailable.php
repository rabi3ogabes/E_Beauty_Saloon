<?php

namespace App\Domain\AIStudio\Exceptions;

/** Every candidate provider failed or none is configured for the capability. */
class NoProviderAvailable extends ProviderException
{
    public function __construct(string $message, public readonly array $attempts = [])
    {
        parent::__construct($message);
    }
}
