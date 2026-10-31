<?php

namespace BlackAirplane\SkyLockReporter;

use Illuminate\Console\Scheduling\Event;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Date;
use Throwable;

/**
 * Tells SkyLock when scheduled tasks start, finish and fail. These are sent
 * from inside schedule:run, before and after each task, so they mustn't hold
 * the scheduler up.
 *
 * - A task that repeats within the minute (`everyFifteenSeconds()` and the
 *   like) is reported for its first run each minute, every failure, and the
 *   first success after one. SkyLock judges lateness by the minute, so its
 *   other runs tell it nothing.
 * - After a send that doesn't get through, a task that runs again within two
 *   minutes isn't sent for a minute: its next run reports it, well inside
 *   SkyLock's five minutes' grace. So an unreachable SkyLock costs about one
 *   timeout a minute, however many tasks run. Failures, and tasks that run
 *   less often, are always sent: missing those would leave SkyLock wrong
 *   until their next run.
 */
final class TaskEvents
{
    /** How long frequent tasks skip SkyLock after a send that didn't get through. */
    public const PAUSE_SECONDS = 60;

    /** A task that runs again within this long is reported by its next run. */
    public const FREQUENT_SECONDS = 120;

    /** @var array<string, string> the minute each repeating task's event was last sent, by key and event */
    private array $sentInMinute = [];

    /** @var array<string, true> repeating tasks whose last run failed */
    private array $failing = [];

    private ?Carbon $pausedUntil = null;

    public function __construct(private readonly SkyLockClient $client)
    {
        //
    }

    /**
     * @param  'starting'|'finished'|'failed'  $event
     */
    public function send(Event $task, string $event, ?int $durationMs = null): void
    {
        $key = ScheduledTasks::key($task);

        if ($task->isRepeatable() && ! $this->firstOrChangedThisMinute($key, $event)) {
            return;
        }

        if ($event !== 'failed' && $this->paused() && $this->frequent($task)) {
            return;
        }

        $response = $this->client->send('POST', 'task-events', array_filter([
            'key' => $key,
            'event' => $event,
            'durationMs' => $durationMs,
        ], fn ($value) => $value !== null));

        if ($response === null) {
            $this->pausedUntil = Date::now()->addSeconds(self::PAUSE_SECONDS);

            return;
        }

        $this->pausedUntil = null;

        if ($task->isRepeatable() && $event !== 'failed') {
            $this->sentInMinute["{$key}|{$event}"] = $this->minute();
            if ($event === 'finished') {
                unset($this->failing[$key]);
            }
        }
    }

    /**
     * A repeating task's first start and finish each minute, every failure,
     * and the first success after one.
     */
    private function firstOrChangedThisMinute(string $key, string $event): bool
    {
        if ($event === 'failed') {
            $this->failing[$key] = true;

            return true;
        }

        if ($event === 'finished' && isset($this->failing[$key])) {
            return true;
        }

        return ($this->sentInMinute["{$key}|{$event}"] ?? null) !== $this->minute();
    }

    private function paused(): bool
    {
        return $this->pausedUntil !== null && Date::now()->lessThan($this->pausedUntil);
    }

    private function frequent(Event $task): bool
    {
        if ($task->isRepeatable()) {
            return true;
        }

        try {
            return Date::now()->diffInSeconds($task->nextRunDate(Date::now())) <= self::FREQUENT_SECONDS;
        } catch (Throwable) {
            // An expression the cron library can't read: send it, as before.
            return false;
        }
    }

    private function minute(): string
    {
        return Date::now()->utc()->format('Y-m-d H:i');
    }
}
