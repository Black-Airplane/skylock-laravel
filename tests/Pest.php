<?php

use BlackAirplane\SkyLockReporter\SkyLockReporterServiceProvider;
use BlackAirplane\SkyLockReporter\Tests\TestCase;
use Illuminate\Console\Scheduling\CacheEventMutex;
use Illuminate\Console\Scheduling\Event;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;

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

/**
 * Fakes SkyLock and returns every task event sent to it, as "label:event".
 *
 * @param  array<string, string>  $labels  task keys to names, to keep assertions readable
 */
function skyLock(array $labels, bool $reachable = true): ArrayObject
{
    $sent = new ArrayObject;
    Http::fake(function (Request $request) use ($sent, $labels, $reachable) {
        $sent[] = ($labels[$request['key']] ?? '?').':'.$request['event'];

        return $reachable ? Http::response(['accepted' => true, 'known' => true], 202) : Http::failedConnection()($request);
    });

    return $sent;
}
