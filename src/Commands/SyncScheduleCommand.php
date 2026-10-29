<?php

namespace BlackAirplane\SkyLockReporter\Commands;

use BlackAirplane\SkyLockReporter\ScheduledTasks;
use BlackAirplane\SkyLockReporter\SkyLockClient;
use Illuminate\Console\Command;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Support\Facades\Cache;

class SyncScheduleCommand extends Command
{
    protected $signature = 'skylock:sync-schedule';

    protected $description = 'Tell SkyLock which scheduled tasks to expect (also happens automatically when they change)';

    public function handle(SkyLockClient $client, Schedule $schedule): int
    {
        $tasks = ScheduledTasks::all($schedule);
        if ($client->send('PUT', 'schedule', ['tasks' => $tasks]) === null) {
            $this->components->error('SkyLock didn\'t accept the schedule. Check SKYLOCK_REPORTER_URL and SKYLOCK_REPORTER_TOKEN.');

            return self::FAILURE;
        }

        Cache::forever('skylock-reporter:schedule', sha1((string) json_encode($tasks)));
        $this->components->info('Sent '.count($tasks).' scheduled '.(count($tasks) === 1 ? 'task' : 'tasks').' to SkyLock.');

        return self::SUCCESS;
    }
}
