<?php

namespace BlackAirplane\SkyLockReporter\Commands;

use BlackAirplane\SkyLockReporter\HealthChecks;
use BlackAirplane\SkyLockReporter\ScheduledTasks;
use BlackAirplane\SkyLockReporter\SkyLockClient;
use Composer\InstalledVersions;
use Illuminate\Console\Command;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Support\Facades\Cache;
use Throwable;

class ReportCommand extends Command
{
    protected $signature = 'skylock:report';

    protected $description = 'Send this app\'s health to SkyLock (runs every minute from the scheduler)';

    public function handle(SkyLockClient $client, HealthChecks $checks, Schedule $schedule): int
    {
        if (! $client->configured()) {
            $this->components->warn('SKYLOCK_REPORTER_TOKEN isn\'t set, so nothing was sent.');

            return self::SUCCESS;
        }

        $response = $client->send('POST', 'health', [
            'phpVersion' => PHP_VERSION,
            'laravelVersion' => app()->version(),
            'packageVersion' => self::packageVersion(),
            'checks' => $checks->run(),
        ]);

        // Send the schedule when it changed, or when SkyLock doesn't have it yet.
        $tasks = ScheduledTasks::all($schedule);
        $hash = sha1((string) json_encode($tasks));
        if ($response !== null && (($response['scheduleKnown'] ?? false) !== true || Cache::get('skylock-reporter:schedule') !== $hash)) {
            if ($client->send('PUT', 'schedule', ['tasks' => $tasks]) !== null) {
                Cache::forever('skylock-reporter:schedule', $hash);
            }
        }

        return self::SUCCESS;
    }

    public static function packageVersion(): string
    {
        try {
            $version = InstalledVersions::getPrettyVersion('blackairplane/skylock-laravel') ?? 'dev';
        } catch (Throwable) {
            $version = 'dev';
        }

        return mb_substr((string) preg_replace('/[^0-9A-Za-z.+\-]/', '', $version), 0, 32) ?: 'dev';
    }
}
