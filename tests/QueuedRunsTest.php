<?php

use BlackAirplane\SkyLockReporter\QueuedOutcomes;
use BlackAirplane\SkyLockReporter\QueuedRuns;
use BlackAirplane\SkyLockReporter\ScheduledTasks;
use Illuminate\Bus\Queueable;
use Illuminate\Console\Events\ScheduledTaskFinished;
use Illuminate\Console\Events\ScheduledTaskStarting;
use Illuminate\Console\Scheduling\Event;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Http\Client\Request;
use Illuminate\Queue\Events\JobTimedOut;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\Jobs\SyncJob;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Sleep;

final class SkyLockTestSucceeds implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable;

    public function handle(): void {}
}

final class SkyLockTestFails implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable;

    public int $tries = 1;

    public function handle(): void
    {
        throw new RuntimeException('The work failed');
    }
}

final class SkyLockTestFansOut implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable;

    public function __construct(public int $failing = 1) {}

    public function handle(): void
    {
        SkyLockTestSucceeds::dispatch()->onConnection('database');
        foreach (range(1, $this->failing) as $_) {
            SkyLockTestFails::dispatch()->onConnection('database');
        }
    }
}

final class SkyLockTestWaitsOnce implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable;

    public static int $runs = 0;

    public function retryUntil(): DateTimeInterface
    {
        return now()->addHour();
    }

    public function handle(): void
    {
        if (++self::$runs === 1) {
            // Like a job waiting out a rate limit
            $this->release(60);
        }
    }
}

/** Run a task the way schedule:run does. */
function runScheduled(Event $task): void
{
    event(new ScheduledTaskStarting($task));
    $task->run(app());
    event(new ScheduledTaskFinished($task, 0.01));
}

function workQueue(): void
{
    test()->artisan('queue:work', ['connection' => 'database', '--stop-when-empty' => true, '--sleep' => 0])->run();
}

/** What the scheduler runs every minute, which sends what the workers noted. */
function minuteReport(): void
{
    test()->artisan('skylock:report')->run();
}

beforeEach(function () {
    reporterConfigured();
    Schema::create('jobs', function (Blueprint $table) {
        $table->id();
        $table->string('queue')->index();
        $table->longText('payload');
        $table->unsignedTinyInteger('attempts');
        $table->unsignedInteger('reserved_at')->nullable();
        $table->unsignedInteger('available_at');
        $table->unsignedInteger('created_at');
    });
    Schema::create('failed_jobs', function (Blueprint $table) {
        $table->id();
        $table->string('uuid')->unique();
        $table->text('connection');
        $table->text('queue');
        $table->longText('payload');
        $table->longText('exception');
        $table->timestamp('failed_at')->useCurrent();
    });
    config(['queue.failed.driver' => 'database-uuids', 'queue.failed.database' => 'testing']);
    SkyLockTestWaitsOnce::$runs = 0;
});

function queuedTask(object $job): Event
{
    return app(Schedule::class)->job($job, connection: 'database');
}

it('reports a queued job\'s task as finished when the job finishes, not when it was queued', function () {
    $task = queuedTask(new SkyLockTestSucceeds);
    $sent = skyLock([ScheduledTasks::key($task) => 'nightly']);

    runScheduled($task);
    expect($sent->getArrayCopy())->toBe(['nightly:starting']);

    // The worker notes it and moves on, without waiting on SkyLock.
    workQueue();
    expect($sent->getArrayCopy())->toBe(['nightly:starting']);

    minuteReport();
    expect($sent->getArrayCopy())->toBe(['nightly:starting', 'nightly:finished']);
});

it('reports the finish once when the job runs before the scheduler is done with the task', function () {
    $task = app(Schedule::class)->job(new SkyLockTestSucceeds, connection: 'sync');
    $sent = skyLock([ScheduledTasks::key($task) => 'nightly']);

    runScheduled($task);

    expect($sent->getArrayCopy())->toBe(['nightly:starting', 'nightly:finished']);
});

it('reports the task as failed when its job fails', function () {
    $task = queuedTask(new SkyLockTestFails);
    $sent = skyLock([ScheduledTasks::key($task) => 'nightly']);

    runScheduled($task);
    workQueue();
    minuteReport();

    expect($sent->getArrayCopy())->toBe(['nightly:starting', 'nightly:failed']);
});

it('follows the work as it fans out, and reports a run\'s failures once', function () {
    $task = queuedTask(new SkyLockTestFansOut(failing: 3));
    $sent = skyLock([ScheduledTasks::key($task) => 'sweep']);

    runScheduled($task);
    workQueue();
    minuteReport();

    expect($sent->getArrayCopy())->toBe(['sweep:starting', 'sweep:finished', 'sweep:failed']);
});

it('waits for a released job to really finish', function () {
    $task = queuedTask(new SkyLockTestWaitsOnce);
    $sent = skyLock([ScheduledTasks::key($task) => 'nightly']);

    runScheduled($task);
    workQueue();
    minuteReport();
    expect(SkyLockTestWaitsOnce::$runs)->toBe(1)
        ->and($sent->getArrayCopy())->toBe(['nightly:starting']);

    $this->travel(61)->seconds();
    workQueue();
    minuteReport();
    expect(SkyLockTestWaitsOnce::$runs)->toBe(2)
        ->and($sent->getArrayCopy())->toBe(['nightly:starting', 'nightly:finished']);
});

it('leaves a task that queued several jobs to report its own finish, as before', function () {
    $task = app(Schedule::class)->call(function () {
        SkyLockTestSucceeds::dispatch()->onConnection('database');
        SkyLockTestSucceeds::dispatch()->onConnection('database');
    })->description('fan-out');
    $sent = skyLock([ScheduledTasks::key($task) => 'fan-out']);

    runScheduled($task);
    workQueue();

    expect($sent->getArrayCopy())->toBe(['fan-out:starting', 'fan-out:finished']);
});

it('tags nothing queued outside a scheduled task', function () {
    $sent = skyLock([]);

    SkyLockTestFails::dispatch()->onConnection('database');
    workQueue();
    minuteReport();

    expect($sent->getArrayCopy())->toBe([]);
});

it('reports the task as failed when the worker kills its job for running too long', function () {
    $task = scheduledEvent("'/usr/bin/php' 'artisan' sync:nightly");
    $sent = skyLock([ScheduledTasks::key($task) => 'nightly']);
    $payload = ['job' => 'Illuminate\\Queue\\CallQueuedHandler@call', 'data' => [], QueuedRuns::PAYLOAD_KEY => [
        'key' => ScheduledTasks::key($task), 'run' => 'run-1', 'depth' => 1,
    ]];

    // The worker fires this, then kills its own process.
    event(new JobTimedOut('database', new SyncJob(app(), json_encode($payload), 'database', 'default')));
    minuteReport();

    expect($sent->getArrayCopy())->toBe(['nightly:failed']);
});

it('keeps what the workers noted while SkyLock is down for the next minute report', function () {
    $task = queuedTask(new SkyLockTestFails);
    $reachable = new ArrayObject(['up' => true]);
    $sent = skyLock([ScheduledTasks::key($task) => 'nightly'], $reachable);

    runScheduled($task);
    workQueue();
    $reachable['up'] = false;
    minuteReport();
    expect($sent->getArrayCopy())->toBe(['nightly:starting']);

    $reachable['up'] = true;
    minuteReport();
    minuteReport();
    expect($sent->getArrayCopy())->toBe(['nightly:starting', 'nightly:failed']);
});

it('tries an outcome SkyLock turned away again at the next minute report, in order', function () {
    $first = queuedTask(new SkyLockTestFails);
    $second = queuedTask(new SkyLockTestSucceeds);
    $labels = [ScheduledTasks::key($first) => 'first', ScheduledTasks::key($second) => 'second'];
    $taking = new ArrayObject(['events' => true]);
    $sent = new ArrayObject;
    Http::fake(function (Request $request) use ($labels, $taking, $sent) {
        if (! str_ends_with($request->url(), '/task-events')) {
            return Http::response(['accepted' => true, 'scheduleKnown' => true, 'stackKnown' => true], 202);
        }
        $sent[] = $labels[$request['key']].':'.$request['event'];

        return $taking['events'] ? Http::response(['accepted' => true], 202) : Http::failedConnection()($request);
    });

    runScheduled($first);
    runScheduled($second);
    workQueue();
    $taking['events'] = false;
    minuteReport();
    $taking['events'] = true;
    minuteReport();

    // Turned away, the first stops the report; both go next time, oldest first.
    expect(array_slice($sent->getArrayCopy(), 2))->toBe(['first:failed', 'first:failed', 'second:finished']);
});

it('sends straight from the worker when it can\'t get the lock on what\'s waiting', function () {
    $task = queuedTask(new SkyLockTestFails);
    $sent = skyLock([ScheduledTasks::key($task) => 'nightly']);
    runScheduled($task);

    // Held elsewhere for longer than a worker waits (time moves on as it waits).
    Cache::lock(QueuedOutcomes::CACHE_KEY.':lock', 10)->get();
    Sleep::fake(syncWithCarbon: true);
    workQueue();

    expect($sent->getArrayCopy())->toBe(['nightly:starting', 'nightly:failed']);
});
