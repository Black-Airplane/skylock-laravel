<?php

use BlackAirplane\SkyLockReporter\ScheduledTasks;
use Illuminate\Console\Events\ScheduledTaskFinished;
use Illuminate\Console\Events\ScheduledTaskStarting;
use Illuminate\Console\Scheduling\Event;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;

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

/** One run of a task, as schedule:run reports it. */
function run(Event $task, bool $fails = false): void
{
    event(new ScheduledTaskStarting($task));
    $task->exitCode = $fails ? 1 : 0;
    event(new ScheduledTaskFinished($task, 1.5));
}

beforeEach(function () {
    reporterConfigured();
    $this->travelTo(now()->utc()->setDate(2026, 11, 2)->setTime(12, 0));
    $this->probe = scheduledEvent("'/usr/bin/php' 'artisan' skylock:probe", '* * * * *')->everyFifteenSeconds();
    $this->minutely = scheduledEvent("'/usr/bin/php' 'artisan' queue:prune-batches", '* * * * *');
    $this->daily = scheduledEvent("'/usr/bin/php' 'artisan' backup:run", '0 12 * * *');
    $this->labels = [
        ScheduledTasks::key($this->probe) => 'probe',
        ScheduledTasks::key($this->minutely) => 'minutely',
        ScheduledTasks::key($this->daily) => 'daily',
    ];
});

it('reports a task that repeats within the minute for its first run each minute', function () {
    $sent = skyLock($this->labels);

    foreach ([0, 15, 30, 45] as $second) {
        $this->travelTo(now()->setTime(12, 0, $second));
        run($this->probe);
    }
    $this->travelTo(now()->setTime(12, 1, 0));
    run($this->probe);
    $this->travelTo(now()->setTime(12, 1, 15));
    run($this->probe);

    expect($sent->getArrayCopy())->toBe(['probe:starting', 'probe:finished', 'probe:starting', 'probe:finished']);
});

it('reports every failure of a repeating task, and the first success after one', function () {
    $sent = skyLock($this->labels);

    foreach ([[0, false], [15, true], [30, true], [45, false]] as [$second, $fails]) {
        $this->travelTo(now()->setTime(12, 0, $second));
        run($this->probe, $fails);
    }
    $this->travelTo(now()->setTime(12, 0, 59));
    run($this->probe);

    expect($sent->getArrayCopy())->toBe(['probe:starting', 'probe:finished', 'probe:failed', 'probe:failed', 'probe:finished']);
});

it('reports every run of a task that doesn’t repeat within the minute', function () {
    $sent = skyLock($this->labels);

    run($this->minutely);
    $this->travelTo(now()->setTime(12, 1));
    run($this->minutely);
    run($this->daily);

    expect($sent->getArrayCopy())->toBe([
        'minutely:starting', 'minutely:finished',
        'minutely:starting', 'minutely:finished',
        'daily:starting', 'daily:finished',
    ]);
});

it('stops waiting on SkyLock for a minute when it can’t be reached, except for failures and tasks that run less often', function () {
    $sent = skyLock($this->labels, reachable: false);

    // The first send times out, so tasks that run again within two minutes skip SkyLock for a minute.
    run($this->minutely);
    run($this->probe);
    // Their next run reports them; a daily task's wouldn't until tomorrow, and a failure is worth the wait.
    run($this->daily);
    run($this->minutely, fails: true);

    // A minute on, it tries again, once.
    $this->travelTo(now()->addSeconds(61));
    run($this->minutely);

    expect($sent->getArrayCopy())->toBe([
        'minutely:starting',
        'daily:starting', 'daily:finished',
        'minutely:failed',
        'minutely:starting',
    ]);
});

it('stops skipping as soon as SkyLock answers again', function () {
    $sent = new ArrayObject;
    $answers = Http::sequence()->pushFailedConnection()->whenEmpty(Http::response(['accepted' => true], 202));
    Http::fake(function (Request $request) use ($sent, $answers) {
        $sent[] = $this->labels[$request['key']].':'.$request['event'];

        return $answers($request);
    });

    event(new ScheduledTaskStarting($this->minutely));
    // A daily task is always sent, and SkyLock answers it.
    run($this->daily);
    run($this->probe);

    expect($sent->getArrayCopy())->toBe(['minutely:starting', 'daily:starting', 'daily:finished', 'probe:starting', 'probe:finished']);
});
