<?php

namespace App\Domain\Integration\Optinexus;

use RuntimeException;

/** A sign-in or logout token that is refused; the message is a stable code the SPA can explain. */
class SsoException extends RuntimeException {}
