<?php

namespace App\Domain\AIStudio\Exceptions;

use RuntimeException;

/** A provider call failed (HTTP error, malformed response...). The router falls back to the next provider. */
class ProviderException extends RuntimeException {}
