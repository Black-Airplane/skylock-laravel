<?php

use BlackAirplane\SkyLockReporter\SkyLockReporterServiceProvider;
use BlackAirplane\SkyLockReporter\Tests\TestCase;
use Illuminate\Console\Scheduling\CacheEventMutex;
use Illuminate\Console\Scheduling\Event;

uses(TestCase::class)->in(__DIR__);

const TOKEN = 'slr_tttttttttttttttttttttttttttttttttttttttt';

/**
 * Configure the reporter, then boot the provider again the way an app with
 * a token in .env would boot it.
 */
function reporterConfigured(): void
{
    config(['skylock-reporter.url' => 'https://skylock.example', 'skylock-reporter.token' => TOKEN]);
    (new SkyLockReporterServiceProvider(app()))->boot();
}

function scheduledEvent(string $command, string $expression = '0 2 * * *'): Event
{
    $event = new Event(app(CacheEventMutex::class), $command, 'UTC');
    $event->cron($expression);

    return $event;
}
