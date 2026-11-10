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

it('leaves out tasks for other environments, which never run here', function () {
    reporterConfigured();
    $schedule = app(Schedule::class);
    $schedule->command('backup:run')->dailyAt('02:00')->environments(['production']);
    $schedule->command('telescope:prune')->daily()->environments(['testing', 'staging']);
    $schedule->command('queue:prune-batches')->daily();

    expect(collect(ScheduledTasks::all($schedule))->pluck('label')->all())->toBe(['telescope:prune', 'queue:prune-batches']);
});

it('strips control characters from labels', function () {
    $event = scheduledEvent("'/usr/bin/php' 'artisan' reports:send")->description("Client\u{0007} reports\n");

    expect(ScheduledTasks::label($event))->toBe('Client  reports');
});

it('takes only a name from a command: never a variable set in front of it, a URL or a path', function (string $command, string $label) {
    expect(ScheduledTasks::label(scheduledEvent($command)))->toBe($label);
})->with([
    'a variable set in front' => ['API_KEY=sk_live_abc123 ./bin/sync.sh', 'sync.sh'],
    'a quoted variable with spaces' => ['GREETING="hello sk_live_abc123" REGION=us /usr/local/bin/sync --all', 'sync'],
    'a URL that mentions artisan' => ['curl -fsS https://example.com/artisan-hook?key=abc123', 'curl'],
    'a URL that ends in artisan' => ['curl https://example.com/artisan abc123', 'curl'],
    'a path that names the client' => ['/home/forge/client.example.com/bin/run --token=abc123', 'run'],
    'a password in an argument' => ['mysqldump -uroot -phunter2 app', 'mysqldump'],
    'artisan by hand' => ['cd /home/forge/client.example.com && php artisan reports:send --token=abc123', 'reports:send'],
    'artisan under a PHP path with a space' => ["'/Users/someone/Library/Application Support/Herd/bin/php85' 'artisan' reports:send --token=abc123", 'reports:send'],
    'artisan by its full path' => ['/usr/bin/php /var/www/client.example.com/artisan backup:run', 'backup:run'],
    'artisan on Windows' => ['"C:\\php\\php.exe" "artisan" backup:run --only-db', 'backup:run'],
    'an option where artisan’s command should be' => ['php artisan --token=abc123', 'php'],
    'nothing that reads as a name' => ['$(cat /etc/secret) | sh', 'Scheduled task'],
    'only a variable' => ['API_KEY=sk_live_abc123', 'Scheduled task'],
]);

it('calls a closure a closure, and names a task whose description is only control characters by its command', function () {
    $schedule = app(Schedule::class);

    expect(ScheduledTasks::label($schedule->call(fn () => null)))->toBe('closure')
        ->and(ScheduledTasks::label(scheduledEvent("'/usr/bin/php' 'artisan' reports:send")->description("\u{0007}\n")))->toBe('reports:send');
});

it('keeps a task’s key as it was, so SkyLock keeps its history', function () {
    // The key is a hash of the whole command; only the label is taken more carefully.
    expect(ScheduledTasks::key(scheduledEvent("'/usr/bin/php8.4' 'artisan' backup:run --only-db")))->toBe(sha1('backup:run --only-db|0 2 * * *|UTC'))
        ->and(ScheduledTasks::key(scheduledEvent('API_KEY=abc ./bin/sync.sh')))->toBe(sha1('API_KEY=abc ./bin/sync.sh|0 2 * * *|UTC'));
});
