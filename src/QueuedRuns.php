<?php

namespace BlackAirplane\SkyLockReporter;

use Illuminate\Console\Scheduling\Event;
use Illuminate\Contracts\Cache\Repository as Cache;
use Illuminate\Contracts\Queue\Job;
use Illuminate\Support\Str;

/**
 * Follows the queued jobs a scheduled task starts, so SkyLock hears how the
 * work went and not just that it was queued.
 *
 * A task like `Schedule::job(new NightlySync)` finishes the moment the job is
 * queued; the real work happens later on a queue worker, and may fail or be
 * killed there without the scheduler ever knowing. So every job queued while
 * a task runs carries that task's key in its payload, and so does every job
 * those jobs queue in turn, however far the work fans out. Then:
 *
 * - A task whose run queued exactly one job (the `Schedule::job` shape)
 *   reports "finished" when that job finishes, with its running time,
 *   instead of when it was queued.
 * - Any job carrying a task's key that fails, or is killed for running too
 *   long, reports "failed" for that task, once per run.
 *
 * Other tasks report as they always have. The scheduler and the workers are
 * separate processes, so the cache records which side reports a run's finish.
 */
final class QueuedRuns
{
    public const PAYLOAD_KEY = 'skylock';

    /** How long a run's notes are kept: longer than any job keeps retrying. */
    private const KEEP_SECONDS = 172800;

    /** @var array{key: string, run: string, depth: int}|null what a job queued now belongs to */
    private ?array $current = null;

    /** @var list<array{current: array{key: string, run: string, depth: int}|null, startedAt: float}> jobs being run, innermost last */
    private array $running = [];

    /** Jobs the current task queued directly, while it ran. */
    private int $queuedByTask = 0;

    public function __construct(
        private readonly TaskEvents $events,
        private readonly Cache $cache,
    ) {}

    public function taskStarting(Event $task): void
    {
        $this->current = ['key' => ScheduledTasks::key($task), 'run' => (string) Str::uuid(), 'depth' => 0];
        $this->queuedByTask = 0;
    }

    /**
     * @return bool Whether the task's finish is left to the job it queued
     */
    public function taskFinished(bool $failed): bool
    {
        $task = $this->current;
        $this->current = null;

        if ($task === null || $failed || $this->queuedByTask !== 1) {
            return false;
        }

        // The job may already have run (a sync queue, a fast worker): whichever
        // side takes the "defer" note reports the finish, so it's sent once.
        $this->cache->put($this->note('defer', $task['run']), true, self::KEEP_SECONDS);
        $durationMs = $this->cache->get($this->note('done', $task['run']));
        if ($durationMs !== null && $this->cache->pull($this->note('defer', $task['run'])) !== null) {
            $this->events->sendForQueuedWork($task['key'], 'finished', (int) $durationMs);
        }

        return true;
    }

    /**
     * What to add to a job's payload as it's queued.
     *
     * @return array<string, array{key: string, run: string, depth: int}>
     */
    public function payload(): array
    {
        if ($this->current === null) {
            return [];
        }

        if ($this->running === []) {
            $this->queuedByTask++;
        }

        return [self::PAYLOAD_KEY => $this->current];
    }

    public function jobProcessing(Job $job): void
    {
        $tag = $this->tag($job);

        $this->running[] = ['current' => $this->current, 'startedAt' => microtime(true)];
        // Jobs this one queues belong to the same run, one level further out.
        $this->current = $tag === null ? null : [...$tag, 'depth' => $tag['depth'] + 1];
    }

    public function jobProcessed(Job $job): void
    {
        $startedAt = $this->leaveJob();
        $tag = $this->tag($job);

        if ($tag === null || $tag['depth'] !== 0 || $job->isReleased() || $job->hasFailed()) {
            return;
        }

        $durationMs = (int) round((microtime(true) - ($startedAt ?? microtime(true))) * 1000);
        $this->cache->put($this->note('done', $tag['run']), $durationMs, self::KEEP_SECONDS);
        if ($this->cache->pull($this->note('defer', $tag['run'])) !== null) {
            $this->events->sendForQueuedWork($tag['key'], 'finished', $durationMs);
        }
    }

    /**
     * The job threw. It may run again; either way it's no longer running here.
     */
    public function jobErrored(): void
    {
        $this->leaveJob();
    }

    /**
     * A job carrying a task's key failed for good, or was killed for running
     * too long: the task's run failed. Reported once, however many fail.
     */
    public function jobFailed(Job $job): void
    {
        $tag = $this->tag($job);
        if ($tag !== null && $this->cache->add($this->note('failed', $tag['run']), true, self::KEEP_SECONDS)) {
            $this->events->sendForQueuedWork($tag['key'], 'failed');
        }
    }

    private function leaveJob(): ?float
    {
        $left = array_pop($this->running);
        $this->current = $left['current'] ?? null;

        return $left['startedAt'] ?? null;
    }

    /**
     * @return array{key: string, run: string, depth: int}|null
     */
    private function tag(Job $job): ?array
    {
        $tag = $job->payload()[self::PAYLOAD_KEY] ?? null;

        return is_array($tag) && is_string($tag['key'] ?? null) && is_string($tag['run'] ?? null) && is_int($tag['depth'] ?? null)
            ? ['key' => $tag['key'], 'run' => $tag['run'], 'depth' => $tag['depth']]
            : null;
    }

    private function note(string $what, string $run): string
    {
        return "skylock-reporter:queued-run:{$run}:{$what}";
    }
}
