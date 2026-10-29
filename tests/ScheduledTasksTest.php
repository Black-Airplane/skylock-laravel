<?php

use BlackAirplane\SkyLockReporter\ScheduledTasks;
use Illuminate\Console\Scheduling\Schedule;

it('describes scheduled tasks without their arguments or the server’s PHP path', function () {
    $a = scheduledEvent("'/usr/bin/php8.4' 'artisan' backup:run --only-db --password=hunter2");
    $b = scheduledEvent("'/opt/php/bin/php' 'artisan' backup:run --only-db --password=hunter2");
    $described = scheduledEvent("'/usr/bin/php' 'artisan' reports:send")->description('Monthly client reports');

    expect(ScheduledTasks::label($a))->toBe('backup:run')
        ->and(ScheduledTasks::key($a))->toBe(ScheduledTasks::key($b))
        ->and(ScheduledTasks::key($a))->toMatch('/^[a-f0-9]{40}$/')
        ->and(ScheduledTasks::key($a))->not->toContain('hunter2')
        ->and(ScheduledTasks::label($described))->toBe('Monthly client reports');
});

it('tells tasks apart by their full command, schedule and time zone', function () {
    $nightly = scheduledEvent("'/usr/bin/php' 'artisan' backup:run --only-db");
    $files = scheduledEvent("'/usr/bin/php' 'artisan' backup:run --only-files");
    $hourly = scheduledEvent("'/usr/bin/php' 'artisan' backup:run --only-db", '0 * * * *');

    expect(ScheduledTasks::key($nightly))->not->toBe(ScheduledTasks::key($files))
        ->and(ScheduledTasks::key($nightly))->not->toBe(ScheduledTasks::key($hourly))
        ->and(ScheduledTasks::label($files))->toBe('backup:run');
});

it('lists the app’s schedule, leaving out its own report', function () {
    reporterConfigured();
    $schedule = app(Schedule::class);
    $schedule->command('backup:run --only-db')->dailyAt('02:00');
    $schedule->call(fn () => null)->hourly()->description('Warm the cache');

    $tasks = ScheduledTasks::all($schedule);

    expect(collect($tasks)->pluck('label')->all())->toBe(['backup:run', 'Warm the cache'])
        ->and($tasks[0])->toHaveKeys(['key', 'label', 'expression', 'timezone'])
        ->and($tasks[0]['expression'])->toBe('0 2 * * *')
        ->and(collect($schedule->events())->contains(fn ($event) => ScheduledTasks::isOwn($event)))->toBeTrue();
});

it('strips control characters from labels', function () {
    $event = scheduledEvent("'/usr/bin/php' 'artisan' reports:send")->description("Client\u{0007} reports\n");

    expect(ScheduledTasks::label($event))->toBe('Client  reports');
});
