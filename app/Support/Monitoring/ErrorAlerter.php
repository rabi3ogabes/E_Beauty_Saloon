<?php

namespace App\Support\Monitoring;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Str;
use Throwable;

/**
 * E-mails the platform owner when an unexpected error is reported. Deduplicated per error (class + file + line)
 * within a throttle window, capped per hour, and never allowed to throw — a broken mailer must not turn one
 * error into two. Repeats suppressed by the throttle are counted and mentioned in the next alert.
 */
class ErrorAlerter
{
    private static bool $sending = false;

    public function enabled(): bool
    {
        return config('monitoring.alert_to') !== []
            && in_array(app()->environment(), config('monitoring.environments'), true);
    }

    public function report(Throwable $e): bool
    {
        if (self::$sending || ! $this->enabled()) {
            return false;
        }

        try {
            $fingerprint = static::fingerprint($e);
            $window = max(1, (int) config('monitoring.throttle_minutes')) * 60;
            $repeatKey = "error-alert:repeats:{$fingerprint}";

            // Already alerted for this error recently: just count it.
            if (! Cache::add("error-alert:sent:{$fingerprint}", true, $window)) {
                Cache::add($repeatKey, 0, $window * 4);
                Cache::increment($repeatKey);

                return false;
            }

            $hourKey = 'error-alert:hour:'.now()->format('YmdH');
            Cache::add($hourKey, 0, 3600);
            if (Cache::increment($hourKey) > max(1, (int) config('monitoring.max_per_hour'))) {
                return false;
            }

            $repeats = (int) Cache::pull($repeatKey, 0);

            self::$sending = true;
            Mail::to(config('monitoring.alert_to'))->send(new ErrorAlertMail(ErrorContext::from($e, $repeats)));

            return true;
        } catch (Throwable) {
            return false; // never let alerting break the request
        } finally {
            self::$sending = false;
        }
    }

    public static function fingerprint(Throwable $e): string
    {
        return substr(sha1(get_class($e).'|'.Str::after($e->getFile(), base_path()).'|'.$e->getLine()), 0, 16);
    }
}
