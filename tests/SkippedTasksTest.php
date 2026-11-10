<?php

use BlackAirplane\SkyLockReporter\ScheduledTasks;
use Illuminate\Console\Events\ScheduledTaskSkipped;
use Illuminate\Console\Scheduling\Event;
use Illuminate\Console\Scheduling\EventMutex;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Support\Facades\Cache;
use RuntimeException;

/**
 * A schedule of its own, so schedule:run sees only these tasks (not the
 * reporter's own report, which would start a process).
 */
function scheduleOf(callable $define): Schedule
{
    $schedule = new Schedule('UTC');
    $define($schedule);
    app()->instance(Schedule::class, $schedule);

    return $schedule;
}

/** @param  list<Event>  $events */
function labelled(array $events): array
{
    return collect($events)->mapWithKeys(fn (Event $event) => [ScheduledTasks::key($event) => (string) $event->description])->all();
}

beforeEach(function () {
    reporterConfigured();
    $this->travelTo(now()->utc()->setDate(2026, 11, 8)->setTime(15, 0));
});

it('says why Laravel skipped each task: its condition, or an earlier run’s lock', function () {
    // Laravel fires the same ScheduledTaskSkipped for both: withoutOverlapping() is a skip() on its lock.
    $schedule = scheduleOf(function (Schedule $schedule): void {
        $schedule->exec('echo benchmark')->everyMinute()->description('benchmark')->when(fn () => false);
        $schedule->exec('echo import')->everyMinute()->description('import')->withoutOverlapping();
    });
    [$benchmark, $import] = $schedule->events();
    $import->mutex->create($import);
    $sent = skyLock(labelled($schedule->events()));

    $this->artisan('schedule:run')->assertSuccessful();

    expect($sent->getArrayCopy())->toBe(['benchmark:skipped:condition', 'import:skipped:overlapping']);
});

it('says when the whole schedule is paused, except for tasks that run even then', function () {
    $schedule = scheduleOf(function (Schedule $schedule): void {
        $schedule->exec('echo benchmark')->everyMinute()->description('benchmark');
        $schedule->exec('echo heartbeat')->everyMinute()->description('heartbeat')->evenWhenPaused()->when(fn () => false);
    });
    Cache::forever('illuminate:schedule:paused', true);
    $sent = skyLock(labelled($schedule->events()));

    $this->artisan('schedule:run')->assertSuccessful();

    expect($sent->getArrayCopy())->toBe(['benchmark:skipped:paused', 'heartbeat:skipped:condition']);
})->skip(! property_exists(Schedule::class, 'pausable'), 'This Laravel can’t pause its schedule.');

it('sends nothing for a skip it can’t explain, so the task still goes late', function () {
    $unreadable = new class implements EventMutex
    {
        public function create(Event $event): bool
        {
            return true;
        }

        public function exists(Event $event): bool
        {
            throw new RuntimeException('Redis went away');
        }

        public function forget(Event $event): void {}
    };
    $import = (new Event($unreadable, 'echo import', 'UTC'))->everyMinute()->description('import')->withoutOverlapping();
    $sent = skyLock(labelled([$import]));

    event(new ScheduledTaskSkipped($import));

    expect($sent->getArrayCopy())->toBe([]);
});

it('reports a repeating task’s skips once a minute, and again when the reason changes', function () {
    $probe = scheduledEvent("'/usr/bin/php' 'artisan' skylock:probe", '* * * * *')->everyFifteenSeconds()->description('probe')->withoutOverlapping();
    $sent = skyLock(labelled([$probe]));

    foreach ([0, 15, 30] as $second) {
        $this->travelTo(now()->setTime(15, 0, $second));
        event(new ScheduledTaskSkipped($probe));
    }
    $probe->mutex->create($probe);
    $this->travelTo(now()->setTime(15, 0, 45));
    event(new ScheduledTaskSkipped($probe));
    $this->travelTo(now()->setTime(15, 1, 0));
    event(new ScheduledTaskSkipped($probe));

    expect($sent->getArrayCopy())->toBe(['probe:skipped:condition', 'probe:skipped:overlapping', 'probe:skipped:overlapping']);
});

it('doesn’t report its own report being skipped', function () {
    $report = scheduledEvent("'/usr/bin/php' 'artisan' skylock:report", '* * * * *')->withoutOverlapping(5);
    $report->mutex->create($report);
    $sent = skyLock([]);

    event(new ScheduledTaskSkipped($report));

    expect($sent->getArrayCopy())->toBe([]);
});
