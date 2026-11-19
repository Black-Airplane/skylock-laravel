<?php

namespace BlackAirplane\SkyLockReporter;

use Illuminate\Contracts\Foundation\Application;
use Illuminate\Support\Facades\Cache;
use Throwable;

/**
 * Which PHP serves web requests. The scheduler's command-line PHP can be a
 * different version (a server with several installed), so about one web
 * request in 50 notes its own, after the response has gone, with one write
 * to the cache. It reads nothing of the request and nothing during it, and
 * does nothing at all when switched off.
 *
 * It's chance, not a counter, because PHP-FPM keeps nothing between
 * requests: counting would mean reading the cache on every one. So nothing
 * that matters waits on it. Everything about the server (its packages, its
 * nginx and whether that's been restarted) is read by the scheduler, with
 * no visitor needed.
 *
 * Each server keeps its own note (by its server key), so servers behind one
 * load balancer that share a cache never take turns at being the one
 * reported; a note lasts a week, so a quiet app keeps it between samples.
 */
final class WebRuntime
{
    public const CACHE_KEY = 'skylock-reporter:web-runtime';

    private const ONE_IN = 50;

    private const KEEP_DAYS = 7;

    /** Under Octane a worker serves many requests: once per worker is enough. */
    private static bool $sampled = false;

    public static function register(Application $app): void
    {
        if (config('skylock-reporter.web_runtime', true) !== true) {
            return;
        }

        $app->terminating(static function (): void {
            self::sample();
        });
    }

    public static function sample(bool $always = false): void
    {
        if (self::$sampled || (! $always && random_int(1, self::ONE_IN) !== 1)) {
            return;
        }

        try {
            $store = (string) config('cache.default');
            // Nothing written there would reach the scheduler's process.
            if (in_array(config("cache.stores.{$store}.driver"), ['array', 'null'], true)) {
                return;
            }

            $note = ['version' => PHP_VERSION, 'sapi' => PHP_SAPI, 'at' => time()];
            // After the response, but still on the request's record in Nightwatch.
            NightwatchSampling::ignore(fn () => Cache::put(self::key(), $note, now()->addDays(self::KEEP_DAYS)));
            self::$sampled = true;
        } catch (Throwable) {
            // The cache is down; the next sample will try again.
        }
    }

    /**
     * This server's note: the web PHP's version and SAPI, and when the note
     * was written (0 when it doesn't say).
     *
     * @return array{version: string, sapi: string, at: int}|null
     */
    public static function read(): ?array
    {
        try {
            $runtime = Cache::get(self::key()) ?? Cache::get(self::CACHE_KEY);
        } catch (Throwable) {
            return null;
        }

        // The cache is shared with the app, so what comes back is checked like anything else read:
        // a version and a SAPI name in the characters those have, or nothing.
        return is_array($runtime)
            && is_string($runtime['version'] ?? null) && preg_match('/^[0-9A-Za-z.+\-]{1,32}$/', $runtime['version']) === 1
            && is_string($runtime['sapi'] ?? null) && preg_match('/^[a-z0-9._-]{1,32}$/', $runtime['sapi']) === 1
            ? ['version' => $runtime['version'], 'sapi' => $runtime['sapi'], 'at' => is_int($runtime['at'] ?? null) ? $runtime['at'] : 0]
            : null;
    }

    public static function forget(): void
    {
        self::$sampled = false;
    }

    /**
     * Where this server keeps its note, by its server key.
     */
    private static function key(): string
    {
        $server = Stack::serverKey();

        return $server === null ? self::CACHE_KEY : self::CACHE_KEY.':'.substr($server, 0, 16);
    }
}
