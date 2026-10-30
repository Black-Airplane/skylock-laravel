<?php

use BlackAirplane\SkyLockReporter\ScheduledTasks;
use Illuminate\Console\Events\ScheduledTaskFinished;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;

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
        && array_keys($request->data()) === ['phpVersion', 'laravelVersion', 'packageVersion', 'debug', 'production', 'checks', 'stackHash']
        && ! str_contains($request->body(), 'sentinel-app-key'));
});
