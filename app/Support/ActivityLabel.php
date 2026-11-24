<?php

namespace App\Support;

use Illuminate\Support\Str;

/** Human label for an activity-log event key ("pos.sale.completed" → "Sale completed"), translated when a label exists. */
class ActivityLabel
{
    public static function for(?string $event): string
    {
        $event = (string) $event;
        if ($event === '') {
            return '—';
        }
        $key = 'workspace.security.events.'.$event;
        if (app('translator')->has($key)) {
            return __($key);
        }
        // No dedicated label yet: turn the key into readable words rather than showing code.
        $words = Str::of($event)->replace(['.', '_'], ' ')->replaceMatches('/^(pos|auth)\s/', '')->squish();

        return Str::ucfirst((string) $words);
    }
}
