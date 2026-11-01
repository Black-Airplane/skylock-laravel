<?php

use BlackAirplane\SkyLockReporter\LocalState;
use BlackAirplane\SkyLockReporter\ScheduledTasks;
use Illuminate\Console\Events\ScheduledTaskFinished;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;

it('sends health every minute, and the schedule when SkyLock doesn’t have it', function () {
    reporterConfigured();
    app(Schedule::class)->command('backup:run')->daily();
    Http::fake([
        'skylock.example/api/ingest/health' => Http::sequence()->push(['accepted' => true, 'scheduleKnown' => false], 202)->push(['accepted' => true, 'scheduleKnown' => true], 202),
        'skylock.example/api/ingest/schedule' => Http::response(['accepted' => true, 'tasks' => 1], 202),
    ]);

    $this->artisan('skylock:report')->assertSuccessful();
    $this->artisan('skylock:report')->assertSuccessful();

    Http::assertSentCount(3);
    Http::assertSent(fn (Request $request): bool => $request->url() === 'https://skylock.example/api/ingest/health'
        && $request->hasHeader('Authorization', 'Bearer '.TOKEN)
        && $request['phpVersion'] === PHP_VERSION
        && collect($request['checks'])->pluck('name')->contains('Database'));
    Http::assertSent(fn (Request $request): bool => $request->url() === 'https://skylock.example/api/ingest/schedule'
        && collect($request['tasks'])->pluck('label')->all() === ['backup:run']);
});

it('adds its report to the scheduler only when it has a token', function () {
    $own = fn (): bool => collect(app(Schedule::class)->events())->contains(fn ($event) => ScheduledTasks::isOwn($event));

    expect($own())->toBeFalse();

    reporterConfigured();
    $report = collect(app(Schedule::class)->events())->first(fn ($event) => ScheduledTasks::isOwn($event));

    expect($own())->toBeTrue()
        ->and($report->expression)->toBe('* * * * *')
        ->and($report->runInBackground)->toBeTrue();
});

it('reports scheduled tasks finishing and failing', function () {
    reporterConfigured();
    Http::fake(['*' => Http::response(['accepted' => true], 202)]);

    $task = scheduledEvent("'/usr/bin/php' 'artisan' backup:run");
    event(new ScheduledTaskFinished($task, 81.234));
    $task->exitCode = 1;
    event(new ScheduledTaskFinished($task, 2.0));

    Http::assertSent(fn (Request $request): bool => $request['event'] === 'finished' && $request['durationMs'] === 81234 && $request['key'] === ScheduledTasks::key($task));
    Http::assertSent(fn (Request $request): bool => $request['event'] === 'failed');
});

it('never breaks the app when SkyLock can’t be reached, and does nothing without a token', function () {
    Http::fake(['*' => Http::failedConnection()]);

    $this->artisan('skylock:report')->assertSuccessful();
    $this->artisan('skylock:test')->assertFailed();
    Http::assertNothingSent();

    reporterConfigured();
    $this->artisan('skylock:report')->assertSuccessful();
    $this->artisan('skylock:sync-schedule')->assertFailed();
    $this->artisan('skylock:test')->assertFailed();
});

it('says whether debug mode is on and whether the app is in production, never the values themselves', function () {
    reporterConfigured();
    config(['app.debug' => true, 'app.key' => 'base64:sentinel-app-key']);
    Http::fake(['*' => Http::response(['accepted' => true, 'scheduleKnown' => true], 202)]);

    $this->artisan('skylock:report')->assertSuccessful();

    Http::assertSent(fn (Request $request): bool => $request->url() === 'https://skylock.example/api/ingest/health'
        && $request['debug'] === true
        && $request['production'] === false
        && array_keys($request->data()) === ['phpVersion', 'laravelVersion', 'packageVersion', 'debug', 'production', 'errorMonitoring', 'checks', 'stackHash', 'stackCollectedAt']
        && ! str_contains($request->body(), 'sentinel-app-key'));
});

it('remembers the schedule and what the app runs on in a file, not in the app’s cache', function () {
    reporterConfigured();
    app(Schedule::class)->command('backup:run')->daily();
    Http::fake([
        'skylock.example/api/ingest/health' => Http::response(['accepted' => true, 'scheduleKnown' => true, 'stackKnown' => true], 202),
        'skylock.example/api/ingest/schedule' => Http::response(['accepted' => true], 202),
    ]);
    $cache = [];
    Event::listen('Illuminate\Cache\Events\*', function (string $name, array $payload) use (&$cache): void {
        $cache[] = class_basename($name).':'.$payload[0]->key;
    });

    $this->artisan('skylock:report')->assertSuccessful();
    $first = $cache;
    $cache = [];
    $this->artisan('skylock:report')->assertSuccessful();

    // The first report sends the schedule and reads what the app runs on; the second does neither.
    expect(Http::recorded(fn (Request $request): bool => str_ends_with($request->url(), '/schedule')))->toHaveCount(1)
        ->and(LocalState::get('schedule'))->toMatch('/^[0-9a-f]{40}$/')
        ->and(LocalState::get('stack'))->toHaveKeys(['deploy', 'until', 'collectedAt', 'stack'])
        // Only the cache check uses the cache now (and the web PHP note, read with the stack).
        ->and(collect($first)->map(fn (string $event) => Str::after($event, ':'))->unique()->sort()->values()->all())->toBe(['skylock-reporter:probe', 'skylock-reporter:web-runtime'])
        ->and($cache)->toBe(['WritingKey:skylock-reporter:probe', 'KeyWritten:skylock-reporter:probe', 'RetrievingKey:skylock-reporter:probe', 'CacheHit:skylock-reporter:probe']);
});

it('reads what the app runs on again after an hour', function () {
    reporterConfigured();
    $this->travelTo(now()->setDate(2026, 11, 2)->setTime(12, 0)->utc());
    Http::fake(['*' => Http::response(['accepted' => true, 'scheduleKnown' => true, 'stackKnown' => true], 202)]);

    $this->artisan('skylock:report')->assertSuccessful();
    $this->travelTo(now()->addMinutes(59));
    $this->artisan('skylock:report')->assertSuccessful();
    $this->travelTo(now()->addMinutes(2));
    $this->artisan('skylock:report')->assertSuccessful();

    $read = Http::recorded(fn (Request $request): bool => str_ends_with($request->url(), '/health'))->map(fn (array $pair) => $pair[0]['stackCollectedAt'])->values()->all();
    expect($read)->toBe(['2026-11-02T12:00:00+00:00', '2026-11-02T12:00:00+00:00', '2026-11-02T13:01:00+00:00']);
});

it('carries on when it can’t keep anything between minutes', function () {
    reporterConfigured();
    LocalState::$path = '/proc/skylock-reporter/state.json';
    Http::fake(['*' => Http::response(['accepted' => true, 'scheduleKnown' => true, 'stackKnown' => true], 202)]);

    $this->artisan('skylock:report')->assertSuccessful();

    expect(LocalState::get('stack'))->toBeNull();
    Http::assertSent(fn (Request $request): bool => str_ends_with($request->url(), '/health'));
});

it('reads a damaged file as nothing kept', function () {
    file_put_contents((string) LocalState::$path, '{"schedule": "abc"');

    expect(LocalState::get('schedule'))->toBeNull();

    LocalState::put('schedule', 'def');

    expect(LocalState::get('schedule'))->toBe('def');
});
