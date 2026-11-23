<?php

namespace BlackAirplane\SkyLockReporter\Commands;

use BlackAirplane\SkyLockReporter\HealthChecks;
use BlackAirplane\SkyLockReporter\LocalState;
use BlackAirplane\SkyLockReporter\QueuedOutcomes;
use BlackAirplane\SkyLockReporter\Revocation;
use BlackAirplane\SkyLockReporter\ScheduledTasks;
use BlackAirplane\SkyLockReporter\SkyLockClient;
use BlackAirplane\SkyLockReporter\Stack;
use BlackAirplane\SkyLockReporter\TaskEvents;
use Composer\InstalledVersions;
use Illuminate\Console\Command;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Date;
use Illuminate\Support\Sleep;
use Throwable;

class ReportCommand extends Command
{
    protected $signature = 'skylock:report
        {--spread : Wait for this app\'s own second of the minute before sending (the scheduler passes it)}';

    protected $description = 'Send this app\'s health to SkyLock (runs every minute from the scheduler)';

    /** How long what the app runs on is kept before it's read again, when nothing is deployed. */
    public const STACK_SECONDS = 3600;

    /** The earliest second past the minute an app reports at, */
    public const SPREAD_FROM = 5;

    /** and how many seconds the apps are spread across: 5 to 39 past the minute. */
    public const SPREAD_SECONDS = 35;

    /** Nothing waits on the report, so SkyLock gets longer to answer it than a task event sent from the scheduler. */
    public const TIMEOUT = 10;

    /** What follows the health report starts within this long of it, */
    public const SEND_FOR_SECONDS = 10;

    /**
     * and everything is done within this long: by 54 seconds past the minute,
     * so one report never runs into the next, which the scheduler's lock
     * (a minute, see the service provider) doesn't stop.
     */
    public const DONE_WITHIN_SECONDS = 15;

    public function handle(SkyLockClient $client, HealthChecks $checks, Schedule $schedule, QueuedOutcomes $outcomes, TaskEvents $events): int
    {
        if (! $client->configured()) {
            $this->components->warn('SKYLOCK_REPORTER_TOKEN isn\'t set, so nothing was sent.');

            return self::SUCCESS;
        }

        // SkyLock revoked the token: nothing is checked or read until the daily check.
        if (Revocation::waiting()) {
            return self::SUCCESS;
        }

        if ($this->option('spread')) {
            $this->waitForOwnSecond();
        }
        $until = Date::now()->addSeconds(self::SEND_FOR_SECONDS);
        $doneBy = Date::now()->addSeconds(self::DONE_WITHIN_SECONDS);

        $read = self::stack();
        $response = $client->send('POST', 'health', [
            ...self::payload($checks->run()),
            'stackHash' => self::hash($read['stack']),
            'stackCollectedAt' => $read['collectedAt'],
        ], timeout: self::TIMEOUT);

        // Whether SkyLock takes task events in batches: the scheduler notes them for this report while it does.
        $batches = ($response['taskEventBatches'] ?? false) === true;
        if ($response !== null && LocalState::get('taskEventBatches') !== $batches) {
            LocalState::put('taskEventBatches', $batches);
        }

        // What it runs on changes rarely: sent when it does or when SkyLock doesn't have it,
        // and read again first when someone has asked SkyLock to check now.
        if ($response !== null && Date::now()->lessThan($until) && ($response['refreshStack'] ?? false) === true) {
            $read = self::stack(fresh: true);
            $client->send('PUT', 'stack', self::stackPayload($read), timeout: self::timeoutBefore($doneBy));
        } elseif ($response !== null && Date::now()->lessThan($until) && ($response['stackKnown'] ?? true) !== true) {
            $client->send('PUT', 'stack', self::stackPayload($read), timeout: self::timeoutBefore($doneBy));
        }

        // Send the schedule when it changed, or when SkyLock doesn't have it yet. Before the task
        // events, so SkyLock knows a task that was just added when its events arrive.
        $tasks = ScheduledTasks::all($schedule);
        $hash = sha1((string) json_encode($tasks));
        if ($response !== null && Date::now()->lessThan($until) && (($response['scheduleKnown'] ?? false) !== true || LocalState::get('schedule') !== $hash)) {
            if ($client->send('PUT', 'schedule', ['tasks' => $tasks], timeout: self::timeoutBefore($doneBy)) !== null) {
                LocalState::put('schedule', $hash);
            }
        }

        // What scheduled tasks did since the last report. While SkyLock can't
        // be reached it waits for the next one.
        if ($response !== null) {
            $this->sendTaskEvents($events, $outcomes, $batches, $until, $doneBy);
        }

        return self::SUCCESS;
    }

    /**
     * This app's second of the minute, taken from its token. Every app's
     * scheduler starts its report at the top of the minute, and SkyLock heard
     * from them all within the same few seconds; each now reports at its own.
     */
    public static function second(string $token): int
    {
        return self::SPREAD_FROM + (int) hexdec(substr(hash('sha256', 'skylock-report-second|'.$token), 0, 7)) % self::SPREAD_SECONDS;
    }

    /**
     * How long a request after the health report may wait for SkyLock: what's
     * left until the report must be done, and never more than TIMEOUT.
     */
    public static function timeoutBefore(Carbon $doneBy): int
    {
        return max(1, min(self::TIMEOUT, (int) floor(Date::now()->diffInSeconds($doneBy, false))));
    }

    private function waitForOwnSecond(): void
    {
        $wait = self::second((string) config('skylock-reporter.token')) - (int) Date::now()->format('s');
        if ($wait > 0) {
            Sleep::for($wait)->seconds();
        }
    }

    /**
     * What the scheduler noted, then what queued work did (it finishes runs the
     * scheduler started), oldest first, in batches. What doesn't get through
     * is kept on the server for the next report; a batch SkyLock read and
     * refused would be refused again, so it isn't.
     */
    private function sendTaskEvents(TaskEvents $events, QueuedOutcomes $outcomes, bool $batches, Carbon $until, Carbon $doneBy): void
    {
        $noted = LocalState::takeNoted();

        if (! $batches) {
            // A SkyLock that doesn't take batches (any more): one at a time, as before 0.6.
            foreach ($noted as $i => $event) {
                if (! Date::now()->lessThan($until) || ! $events->sendNoted($event)) {
                    LocalState::keepNoted(array_slice($noted, $i));

                    return;
                }
            }
            LocalState::keepNoted([]);
            $outcomes->send();

            return;
        }

        $waiting = [...$noted, ...$outcomes->take()];
        foreach (array_chunk($waiting, TaskEvents::BATCH) as $i => $batch) {
            if (! Date::now()->lessThan($until) || $events->sendBatch($batch, self::timeoutBefore($doneBy)) === 'failed') {
                LocalState::keepNoted(array_slice($waiting, $i * TaskEvents::BATCH));

                return;
            }
        }
        LocalState::keepNoted([]);
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
            $deploy = Stack::deployFingerprint();
            $kept = $fresh ? null : LocalState::get('stack');
            if (is_array($kept)
                && ($kept['deploy'] ?? null) === $deploy
                && is_int($kept['until'] ?? null) && $kept['until'] > Date::now()->getTimestamp()
                && is_array($kept['stack'] ?? null) && is_string($kept['collectedAt'] ?? null)) {
                return ['collectedAt' => $kept['collectedAt'], 'stack' => $kept['stack']];
            }

            $read = self::read();
            LocalState::put('stack', ['deploy' => $deploy, 'until' => Date::now()->getTimestamp() + self::STACK_SECONDS, ...$read]);

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
