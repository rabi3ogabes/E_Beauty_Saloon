<?php

return [
    /*
    | E-mail alerts for unexpected server errors (500s, failed jobs, crashed commands).
    | ERROR_ALERT_EMAIL: one address or several, comma-separated. Empty = alerts off.
    */
    'alert_to' => array_values(array_filter(array_map('trim', explode(',', (string) env('ERROR_ALERT_EMAIL', ''))))),

    // Only alert from these environments (local errors stay in the log).
    'environments' => array_values(array_filter(array_map('trim', explode(',', (string) env('ERROR_ALERT_ENVIRONMENTS', 'production'))))),

    // The same error (class + file + line) is e-mailed at most once per window; repeats are counted in the next alert.
    'throttle_minutes' => (int) env('ERROR_ALERT_THROTTLE', 15),

    // Hard cap so an outage never floods the inbox.
    'max_per_hour' => (int) env('ERROR_ALERT_MAX_PER_HOUR', 20),
];
