<?php

namespace BlackAirplane\SkyLockReporter;

use Illuminate\Contracts\Cache\LockProvider;
use Illuminate\Contracts\Cache\Repository as Cache;
use Throwable;

/**
 * How the queued work of scheduled tasks went (see QueuedRuns), noted by the
 * queue workers in the app's cache and sent by the next minute report.
 *
 * A worker that told SkyLock itself waited for SkyLock's answer inside the
 * job: a few hundred milliseconds on every run of a frequent task, up to the
 * timeout when SkyLock was slow, all of it counted as the job's own time. The
 * report runs every minute in a process of its own. SkyLock judges a task late
 * by when it next starts, which the scheduler still sends as it happens, so a
 * finish or failure arriving within the minute changes nothing it decides.
 *
 * Workers and the report share the list under a cache lock. A store without
 * locks gets the old behavior: the worker sends straight away.
 */
final class QueuedOutcomes
{
    public const CACHE_KEY = 'skylock-reporter:queued-outcomes';

    /** Long enough for a report that's stopped for a while, not forever. */
    private const KEEP_SECONDS = 172800;

    /** If the report stops running, the oldest are dropped past this many. */
    private const MAX = 500;

    public function __construct(
        private readonly Cache $cache,
        private readonly TaskEvents $events,
    ) {}

    /**
     * @param  'finished'|'failed'  $event
     */
    public function add(string $key, string $event, ?int $durationMs = null): void
    {
        // SkyLock revoked the token: nothing would ever send them.
        if (Revocation::active()) {
            return;
        }

        if (! $this->keep([['key' => $key, 'event' => $event, 'durationMs' => $durationMs]])) {
            $this->events->sendForQueuedWork($key, $event, $durationMs);
        }
    }

    /**
     * Sends what the workers noted, oldest first. What SkyLock doesn't take
     * waits for the next report.
     */
    public function send(): void
    {
        try {
            // The one cache read a minute when there's nothing to send.
            if ($this->pending() === []) {
                return;
            }
        } catch (Throwable) {
            return;
        }

        $outcomes = [];
        $taken = $this->locked(function () use (&$outcomes): void {
            $outcomes = $this->pending();
            $this->cache->forget(self::CACHE_KEY);
        });
        if (! $taken) {
            return;
        }

        foreach ($outcomes as $i => $outcome) {
            if (! $this->events->sendForQueuedWork($outcome['key'], $outcome['event'], $outcome['durationMs'])) {
                $this->keep(array_slice($outcomes, $i), ahead: true);

                return;
            }
        }
    }

    /**
     * @param  list<array{key: string, event: 'finished'|'failed', durationMs: int|null}>  $outcomes
     * @param  bool  $ahead  Put them before what's already waiting (they're older)
     * @return bool Whether they were kept
     */
    private function keep(array $outcomes, bool $ahead = false): bool
    {
        return $this->locked(function () use ($outcomes, $ahead): void {
            $all = $ahead ? [...$outcomes, ...$this->pending()] : [...$this->pending(), ...$outcomes];
            $this->cache->put(self::CACHE_KEY, array_slice($all, -self::MAX), self::KEEP_SECONDS);
        });
    }

    /**
     * @param  callable(): void  $callback
     * @return bool Whether it ran
     */
    private function locked(callable $callback): bool
    {
        $store = $this->cache->getStore();
        if (! $store instanceof LockProvider) {
            return false;
        }

        try {
            $store->lock(self::CACHE_KEY.':lock', 10)->block(3, $callback);

            return true;
        } catch (Throwable) {
            return false;
        }
    }

    /**
     * @return list<array{key: string, event: 'finished'|'failed', durationMs: int|null}>
     */
    private function pending(): array
    {
        $pending = $this->cache->get(self::CACHE_KEY);
        if (! is_array($pending)) {
            return [];
        }

        $outcomes = [];
        foreach ($pending as $outcome) {
            if (is_array($outcome) && is_string($outcome['key'] ?? null) && in_array($outcome['event'] ?? null, ['finished', 'failed'], true)) {
                $outcomes[] = [
                    'key' => $outcome['key'],
                    'event' => $outcome['event'],
                    'durationMs' => is_int($outcome['durationMs'] ?? null) ? $outcome['durationMs'] : null,
                ];
            }
        }

        return $outcomes;
    }
}
