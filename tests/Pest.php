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
 * Fakes SkyLock and returns every task event sent to it, as "label:event",
 * or "label:skipped:reason".
 * Anything else, like the minute report's health, is answered but not listed.
 *
 * @param  array<string, string>  $labels  task keys to names, to keep assertions readable
 * @param  bool|ArrayObject<string, bool>  $reachable  an ArrayObject's `up` can change mid-test
 */
function skyLock(array $labels, bool|ArrayObject $reachable = true): ArrayObject
{
    $sent = new ArrayObject;
    Http::fake(function (Request $request) use ($sent, $labels, $reachable) {
        $up = is_bool($reachable) ? $reachable : $reachable['up'];
        if (! str_ends_with($request->url(), '/task-events')) {
            return $up ? Http::response(['accepted' => true, 'scheduleKnown' => true, 'stackKnown' => true], 202) : Http::failedConnection()($request);
        }

        $sent[] = ($labels[$request['key']] ?? '?').':'.$request['event'].(isset($request['reason']) ? ':'.$request['reason'] : '');

        return $up ? Http::response(['accepted' => true, 'known' => true], 202) : Http::failedConnection()($request);
    });

    return $sent;
}
