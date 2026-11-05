<?php

namespace App\Domain\Integration\Optinexus;

use RuntimeException;

/** OptiNexus could not give a usable answer. Access fails closed: nothing is granted and nothing stale is reused. */
class IdentityProviderUnavailable extends RuntimeException
{
    public function __construct(string $message, public readonly bool $transient = false)
    {
        parent::__construct($message);
    }
}
