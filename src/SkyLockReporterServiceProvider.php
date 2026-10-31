<?php

namespace BlackAirplane\SkyLockReporter;

use BlackAirplane\SkyLockReporter\Commands\ReportCommand;
use BlackAirplane\SkyLockReporter\Commands\SyncScheduleCommand;
use BlackAirplane\SkyLockReporter\Commands\TestCommand;
use Illuminate\Console\Events\ScheduledBackgroundTaskFinished;
use Illuminate\Console\Events\ScheduledTaskFailed;
use Illuminate\Console\Events\ScheduledTaskFinished;
use Illuminate\Console\Events\ScheduledTaskStarting;
use Illuminate\Console\Scheduling\Event;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Support\Facades\Event as Events;
use Illuminate\Support\ServiceProvider;

class SkyLockReporterServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->mergeConfigFrom(__DIR__.'/../config/skylock-reporter.php', 'skylock-reporter');
        $this->app->singleton(SkyLockClient::class);
        // One per process: it remembers what this scheduler run has sent.
        $this->app->singleton(TaskEvents::class);
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
        $this->callAfterResolving(Schedule::class, function (Schedule $schedule): void {
            $schedule->command('skylock:report')->everyMinute()->runInBackground()->withoutOverlapping(5);
        });

        Events::listen(ScheduledTaskStarting::class, fn (ScheduledTaskStarting $event) => $this->task($event->task, 'starting'));
        Events::listen(ScheduledTaskFinished::class, fn (ScheduledTaskFinished $event) => $this->task($event->task, $this->failed($event->task) ? 'failed' : 'finished', (int) round($event->runtime * 1000)));
        Events::listen(ScheduledBackgroundTaskFinished::class, fn (ScheduledBackgroundTaskFinished $event) => $this->task($event->task, $this->failed($event->task) ? 'failed' : 'finished'));
        Events::listen(ScheduledTaskFailed::class, fn (ScheduledTaskFailed $event) => $this->task($event->task, 'failed'));
    }

    private function failed(Event $task): bool
    {
        return $task->exitCode !== null && $task->exitCode !== 0;
    }

    /**
     * @param  'starting'|'finished'|'failed'  $event
     */
    private function task(Event $task, string $event, ?int $durationMs = null): void
    {
        if (ScheduledTasks::isOwn($task) || ($event === 'finished' && $task->runInBackground && $durationMs !== null)) {
            // Background tasks report when they really finish, not when they're launched.
            return;
        }

        $this->app->make(TaskEvents::class)->send($task, $event, $durationMs);
    }
}
