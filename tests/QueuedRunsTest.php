<?php

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
use Illuminate\Queue\Events\JobTimedOut;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\Jobs\SyncJob;
use Illuminate\Support\Facades\Schema;

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

    workQueue();
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

    expect($sent->getArrayCopy())->toBe(['nightly:starting', 'nightly:failed']);
});

it('follows the work as it fans out, and reports a run\'s failures once', function () {
    $task = queuedTask(new SkyLockTestFansOut(failing: 3));
    $sent = skyLock([ScheduledTasks::key($task) => 'sweep']);

    runScheduled($task);
    workQueue();

    expect($sent->getArrayCopy())->toBe(['sweep:starting', 'sweep:finished', 'sweep:failed']);
});

it('waits for a released job to really finish', function () {
    $task = queuedTask(new SkyLockTestWaitsOnce);
    $sent = skyLock([ScheduledTasks::key($task) => 'nightly']);

    runScheduled($task);
    workQueue();
    expect(SkyLockTestWaitsOnce::$runs)->toBe(1)
        ->and($sent->getArrayCopy())->toBe(['nightly:starting']);

    $this->travel(61)->seconds();
    workQueue();
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

    expect($sent->getArrayCopy())->toBe(['nightly:failed']);
});
