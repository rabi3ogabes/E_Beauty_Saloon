<?php

namespace App\Domain\AIStudio\Contracts;

/**
 * Content moderation hook for studio inputs and outputs. Bind a real implementation
 * (e.g. a vision safety API) in a service provider; the default checks image integrity only.
 */
interface ModeratesContent
{
    /** Return null when allowed, or a translated rejection reason. $stage: input|output */
    public function check(string $image, string $stage): ?string;
}
