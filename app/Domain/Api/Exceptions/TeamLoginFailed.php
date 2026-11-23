<?php

namespace App\Domain\Api\Exceptions;

use Illuminate\Http\JsonResponse;
use RuntimeException;

/** Staff / owner sign-in failure with a stable machine code (invalid_credentials, mfa_required, …). */
class TeamLoginFailed extends RuntimeException
{
    public function __construct(string $message, public readonly string $errorCode, public readonly int $status = 422, public readonly array $extra = [])
    {
        parent::__construct($message);
    }

    /** Rendered by Laravel (renderable exception); ApiResponseEnvelope moves `code` into error.code. */
    public function render(): JsonResponse
    {
        return response()->json(array_merge(['message' => $this->getMessage(), 'code' => $this->errorCode], $this->extra), $this->status);
    }
}
