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

    /** How long what the app runs on is kept before it's read again, when nothing is deployed. */
    public const STACK_SECONDS = 3600;

    public function handle(SkyLockClient $client, HealthChecks $checks, Schedule $schedule): int
    {
        if (! $client->configured()) {
            $this->components->warn('SKYLOCK_REPORTER_TOKEN isn\'t set, so nothing was sent.');

            return self::SUCCESS;
        }

        $read = self::stack();
        $response = $client->send('POST', 'health', [
            ...self::payload($checks->run()),
            'stackHash' => self::hash($read['stack']),
            'stackCollectedAt' => $read['collectedAt'],
        ]);

        // What it runs on changes rarely: sent when it does or when SkyLock doesn't have it,
        // and read again first when someone has asked SkyLock to check now.
        if ($response !== null && ($response['refreshStack'] ?? false) === true) {
            $read = self::stack(fresh: true);
            $client->send('PUT', 'stack', self::stackPayload($read));
        } elseif ($response !== null && ($response['stackKnown'] ?? true) !== true) {
            $client->send('PUT', 'stack', self::stackPayload($read));
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
     * The minute report: versions, three booleans about the app's own
     * settings (never the values themselves) and the health checks.
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
            'errorMonitoring' => Stack::errorMonitoring(),
            'checks' => $checks,
        ];
    }

    /**
     * What the app runs on, and when it was read: at most once an hour,
     * again after every deploy, and straight away when SkyLock asks.
     *
     * @return array{collectedAt: string, stack: array<mixed>}
     */
    public static function stack(bool $fresh = false): array
    {
        try {
            $key = 'skylock-reporter:stack:'.Stack::deployFingerprint();
            $cached = $fresh ? null : Cache::get($key);
            if (is_array($cached) && is_array($cached['stack'] ?? null) && is_string($cached['collectedAt'] ?? null)) {
                return ['collectedAt' => $cached['collectedAt'], 'stack' => $cached['stack']];
            }

            $read = self::read();
            Cache::put($key, $read, self::STACK_SECONDS);

            return $read;
        } catch (Throwable) {
            return self::read();
        }
    }

    /**
     * @return array{collectedAt: string, stack: array<mixed>}
     */
    private static function read(): array
    {
        return ['collectedAt' => now()->utc()->toIso8601String(), 'stack' => Stack::collect()];
    }

    /**
     * @param  array{collectedAt: string, stack: array<mixed>}  $read
     * @return array<mixed>
     */
    public static function stackPayload(array $read): array
    {
        return [...$read['stack'], 'collectedAt' => $read['collectedAt']];
    }

    /**
     * @param  array<mixed>  $stack
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
