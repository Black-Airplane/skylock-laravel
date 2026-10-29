<?php

return [
    /*
    | Where SkyLock lives, and this app's reporter token. Create the token on
    | the application's Status tab in SkyLock. Without a token the package
    | does nothing at all.
    */

    'url' => env('SKYLOCK_REPORTER_URL', 'https://getskylock.com'),

    'token' => env('SKYLOCK_REPORTER_TOKEN'),

    /*
    | Warn when these are exceeded. A warning makes the app "degraded" in
    | SkyLock after three reports in a row; a failure makes it unhealthy.
    */

    'thresholds' => [
        'queue_size' => 1000,
        'oldest_job_seconds' => 900,
        'disk_used_warn' => 90,
        'disk_used_fail' => 98,
    ],

    // Seconds to wait for SkyLock. The reporter never slows the app down for longer.
    'timeout' => 3,
];
