<?php

namespace BlackAirplane\SkyLockReporter;

use BlackAirplane\SkyLockReporter\Commands\ReportCommand;
use BlackAirplane\SkyLockReporter\Commands\SyncScheduleCommand;
use BlackAirplane\SkyLockReporter\Commands\TestCommand;
use Illuminate\Console\Events\CommandStarting;
use Illuminate\Console\Events\ScheduledBackgroundTaskFinished;
use Illuminate\Console\Events\ScheduledTaskFailed;
use Illuminate\Console\Events\ScheduledTaskFinished;
use Illuminate\Console\Events\ScheduledTaskStarting;
use Illuminate\Console\Scheduling\Event;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Queue\Events\JobExceptionOccurred;
use Illuminate\Queue\Events\JobFailed;
use Illuminate\Queue\Events\JobProcessed;
use Illuminate\Queue\Events\JobProcessing;
use Illuminate\Queue\Events\JobTimedOut;
use Illuminate\Support\Facades\Event as Events;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\ServiceProvider;

class SkyLockReporterServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->mergeConfigFrom(__DIR__.'/../config/skylock-reporter.php', 'skylock-reporter');
        $this->app->singleton(SkyLockClient::class);
        // One per process: it remembers what this scheduler run has sent.
        $this->app->singleton(TaskEvents::class);
        // One per process: it knows which task or job is running in it.
        $this->app->singleton(QueuedRuns::class, fn ($app) => new QueuedRuns($app->make(TaskEvents::class), $app->make('cache.store')));
    }

    public function boot(): void
    {
        $this->publishes([__DIR__.'/../config/skylock-reporter.php' => config_path('skylock-reporter.php')], 'skylock-reporter');

        if (! $this->app->runningInConsole()) {
            if ($this->app->make(SkyLockClient::class)->configured()) {
                WebRuntime::register($this->app);
            }

            return;
        }

        $this->commands([ReportCommand::class, SyncScheduleCommand::class, TestCommand::class]);

        if (! $this->app->make(SkyLockClient::class)->configured()) {
            return;
        }

        // The report every minute doubles as proof the scheduler is running.
        // Overlap protection stays: a check on a database that hangs has no timeout.
        $this->callAfterResolving(Schedule::class, function (Schedule $schedule): void {
            NightwatchSampling::task($schedule->command('skylock:report')->everyMinute()->runInBackground()->withoutOverlapping(5));
        });

        // Each report runs in its own process, which Nightwatch samples at its
        // command rate as it starts. Nightwatch's listener was added when the
        // providers registered, so this one runs after it and decides. Only a
        // process's first command decides, as with Nightwatch.
        $decided = false;
        Events::listen(CommandStarting::class, function (CommandStarting $event) use (&$decided): void {
            if ($decided) {
                return;
            }
            $decided = true;

            if ($event->command === 'skylock:report') {
                NightwatchSampling::command();
            }
        });

        Events::listen(ScheduledTaskStarting::class, fn (ScheduledTaskStarting $event) => $this->task($event->task, 'starting'));
        Events::listen(ScheduledTaskFinished::class, fn (ScheduledTaskFinished $event) => $this->task($event->task, $this->failed($event->task) ? 'failed' : 'finished', (int) round($event->runtime * 1000), runEnded: true));
        Events::listen(ScheduledBackgroundTaskFinished::class, fn (ScheduledBackgroundTaskFinished $event) => $this->task($event->task, $this->failed($event->task) ? 'failed' : 'finished'));
        Events::listen(ScheduledTaskFailed::class, fn (ScheduledTaskFailed $event) => $this->task($event->task, 'failed', runEnded: true));

        $this->followQueuedWork();
    }

    /**
     * Jobs a scheduled task queues report how the work went (see QueuedRuns).
     */
    private function followQueuedWork(): void
    {
        $runs = fn (): QueuedRuns => $this->app->make(QueuedRuns::class);

        Queue::createPayloadUsing(fn () => $runs()->payload());

        Events::listen(JobProcessing::class, fn (JobProcessing $event) => $runs()->jobProcessing($event->job));
        Events::listen(JobProcessed::class, fn (JobProcessed $event) => $runs()->jobProcessed($event->job));
        Events::listen(JobExceptionOccurred::class, fn () => $runs()->jobErrored());
        Events::listen(JobFailed::class, fn (JobFailed $event) => $runs()->jobFailed($event->job));
        // The worker kills the process right after, so nothing else would tell SkyLock.
        Events::listen(JobTimedOut::class, fn (JobTimedOut $event) => $runs()->jobFailed($event->job));
    }

    private function failed(Event $task): bool
    {
        return $task->exitCode !== null && $task->exitCode !== 0;
    }

    /**
     * @param  'starting'|'finished'|'failed'  $event
     * @param  bool  $runEnded  The scheduler finished running the task (not a background task finishing later)
     */
    private function task(Event $task, string $event, ?int $durationMs = null, bool $runEnded = false): void
    {
        if (ScheduledTasks::isOwn($task)) {
            return;
        }

        $runs = $this->app->make(QueuedRuns::class);
        if ($event === 'starting') {
            $runs->taskStarting($task);
        } elseif ($runEnded && $runs->taskFinished(failed: $event === 'failed')) {
            // All the task did was queue one job: that job reports the finish.
            return;
        }

        if ($event === 'finished' && $task->runInBackground && $durationMs !== null) {
            // Background tasks report when they really finish, not when they're launched.
            return;
        }

        $this->app->make(TaskEvents::class)->send($task, $event, $durationMs);
    }
}
