<?php

namespace BlackAirplane\SkyLockReporter\Commands;

use BlackAirplane\SkyLockReporter\HealthChecks;
use BlackAirplane\SkyLockReporter\ScheduledTasks;
use BlackAirplane\SkyLockReporter\SkyLockClient;
use BlackAirplane\SkyLockReporter\Stack;
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

        $stack = self::stack();
        $response = $client->send('POST', 'health', [...self::payload($checks->run()), 'stackHash' => self::hash($stack)]);

        // What it runs on changes rarely: sent when it does, or when SkyLock asks.
        if ($response !== null && ($response['stackKnown'] ?? true) !== true) {
            $client->send('PUT', 'stack', $stack);
        }

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

    /**
     * The minute report: versions, two booleans about the app's own settings
     * (never the values themselves) and the health checks.
     *
     * @param  list<array<string, mixed>>  $checks
     * @return array<string, mixed>
     */
    public static function payload(array $checks): array
    {
        return [
            'phpVersion' => PHP_VERSION,
            'laravelVersion' => app()->version(),
            'packageVersion' => self::packageVersion(),
            'debug' => config('app.debug') === true,
            'production' => app()->isProduction(),
            'checks' => $checks,
        ];
    }

    /**
     * What the app runs on, worked out at most once an hour.
     *
     * @return array<string, mixed>
     */
    public static function stack(): array
    {
        try {
            $stack = Cache::remember('skylock-reporter:stack', 3600, fn (): array => Stack::collect());
        } catch (Throwable) {
            $stack = null;
        }

        return is_array($stack) ? $stack : Stack::collect();
    }

    /**
     * @param  array<string, mixed>  $stack
     */
    public static function hash(array $stack): string
    {
        return sha1((string) json_encode($stack));
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
