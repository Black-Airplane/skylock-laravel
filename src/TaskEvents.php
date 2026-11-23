<?php

namespace BlackAirplane\SkyLockReporter;

use Illuminate\Console\Scheduling\Event;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Date;
use Throwable;

/**
 * Tells SkyLock when scheduled tasks start, finish, fail or are skipped (and
 * why). They happen inside schedule:run, before and after each task, so
 * telling mustn't hold the scheduler up.
 *
 * - While SkyLock takes batches (it says so in its answer to every minute
 *   report), an event is noted on the server (LocalState), and the minute
 *   report sends what's been noted at its own second of the minute. Nothing
 *   in the scheduler waits on SkyLock, and an event that doesn't get through
 *   is sent the next minute instead of being lost.
 * - A task that repeats within the minute (`everyFifteenSeconds()` and the
 *   like) is reported for its first run each minute, every failure, and the
 *   first success after one. SkyLock judges lateness by the minute, so its
 *   other runs tell it nothing.
 * - Otherwise (a SkyLock before batches, or storage that can't be written),
 *   events are sent straight away, as before 0.6. After a send that doesn't
 *   get through, a task that runs again within two minutes isn't sent for a
 *   minute: its next run reports it, well inside SkyLock's five minutes'
 *   grace. So an unreachable SkyLock costs about one timeout a minute,
 *   however many tasks run. Failures, and tasks that run less often, are
 *   always sent: missing those would leave SkyLock wrong until their next run.
 */
final class TaskEvents
{
    /** How long frequent tasks skip SkyLock after a send that didn't get through. */
    public const PAUSE_SECONDS = 60;

    /** A task that runs again within this long is reported by its next run. */
    public const FREQUENT_SECONDS = 120;

    /** The most events one batch carries; SkyLock takes no more. */
    public const BATCH = 100;

    /** @var array<string, string> the minute each repeating task's event was last sent, by key and event */
    private array $sentInMinute = [];

    /** @var array<string, true> repeating tasks whose last run failed */
    private array $failing = [];

    private ?Carbon $pausedUntil = null;

    /** Whether SkyLock takes batches, as the last minute report heard; read once a process. */
    private ?bool $batches = null;

    public function __construct(private readonly SkyLockClient $client)
    {
        //
    }

    /**
     * @param  'starting'|'finished'|'failed'|'skipped'  $event
     * @param  'paused'|'overlapping'|'condition'|null  $reason  why a task was skipped
     */
    public function send(Event $task, string $event, ?int $durationMs = null, ?string $reason = null): void
    {
        $key = ScheduledTasks::key($task);
        // A repeating task skipped for a new reason within the minute is news.
        $kind = $reason === null ? $event : "{$event}:{$reason}";

        if ($task->isRepeatable() && ! $this->firstOrChangedThisMinute($key, $kind)) {
            return;
        }

        if ($this->batches() && LocalState::note(self::noted($key, $event, $durationMs, $reason))) {
            $this->told($task, $key, $kind, $event);

            return;
        }

        if ($event !== 'failed' && $this->paused() && $this->frequent($task)) {
            return;
        }

        $response = $this->post($key, $event, $durationMs, $reason);

        if ($response === null) {
            $this->pausedUntil = Date::now()->addSeconds(self::PAUSE_SECONDS);

            return;
        }

        $this->pausedUntil = null;
        $this->told($task, $key, $kind, $event);
    }

    /**
     * An event as it waits to be sent: an id SkyLock knows it by if the batch
     * comes twice, and when it was noted, so one that has waited too long is
     * dropped. That time isn't sent.
     *
     * @return array{id: string, key: string, event: string, reason?: string, durationMs?: int, at: int}
     */
    public static function noted(string $key, string $event, ?int $durationMs = null, ?string $reason = null): array
    {
        return array_filter([
            'id' => bin2hex(random_bytes(8)),
            'key' => $key,
            'event' => $event,
            'reason' => $reason,
            'durationMs' => $durationMs,
            'at' => Date::now()->getTimestamp(),
        ], fn ($value) => $value !== null);
    }

    /**
     * Sends waiting events as one batch, oldest first.
     *
     * @param  list<array<string, mixed>>  $events
     * @return 'sent'|'refused'|'failed' refused: SkyLock read it and won't take it, so it isn't sent again
     */
    public function sendBatch(array $events, int $timeout): string
    {
        $batch = array_map(fn (array $event): array => array_intersect_key($event, array_flip(['id', 'key', 'event', 'reason', 'durationMs'])), $events);

        if ($this->client->send('POST', 'task-events/batch', ['events' => $batch], timeout: $timeout) !== null) {
            return 'sent';
        }

        return $this->client->refused() ? 'refused' : 'failed';
    }

    /**
     * One waiting event, on its own: what was noted while SkyLock took
     * batches, sent once it no longer does.
     *
     * @param  array<string, mixed>  $event
     */
    public function sendNoted(array $event): bool
    {
        return $this->post((string) $event['key'], (string) $event['event'], is_int($event['durationMs'] ?? null) ? $event['durationMs'] : null, is_string($event['reason'] ?? null) ? $event['reason'] : null) !== null;
    }

    /**
     * Noted or sent: a repeating task's run isn't told again this minute.
     */
    private function told(Event $task, string $key, string $kind, string $event): void
    {
        if ($task->isRepeatable() && $event !== 'failed') {
            $this->sentInMinute["{$key}|{$kind}"] = $this->minute();
            if ($event === 'finished') {
                unset($this->failing[$key]);
            }
        }
    }

    private function batches(): bool
    {
        return $this->batches ??= LocalState::get('taskEventBatches') === true;
    }

    /**
     * The outcome of the work a scheduled task queued (see QueuedRuns), sent
     * by the minute report, or by the scheduler when the work finished first.
     * Neither sends it again, so there's nothing to pause.
     *
     * @param  'finished'|'failed'  $event
     * @return bool Whether SkyLock took it
     */
    public function sendForQueuedWork(string $key, string $event, ?int $durationMs = null): bool
    {
        return $this->post($key, $event, $durationMs) !== null;
    }

    /**
     * @return array<string, mixed>|null
     */
    private function post(string $key, string $event, ?int $durationMs, ?string $reason = null): ?array
    {
        // Sent inside the app's own task or job, so Nightwatch would put it on that record.
        return NightwatchSampling::ignore(fn (): ?array => $this->client->send('POST', 'task-events', array_filter([
            'key' => $key,
            'event' => $event,
            'reason' => $reason,
            'durationMs' => $durationMs,
        ], fn ($value) => $value !== null)));
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
