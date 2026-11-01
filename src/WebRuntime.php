<?php

namespace BlackAirplane\SkyLockReporter;

use Illuminate\Contracts\Foundation\Application;
use Illuminate\Support\Facades\Cache;
use Throwable;

/**
 * Which PHP serves web requests. The scheduler's command-line PHP can be a
 * different version (a server with several installed), so about one web
 * request in 500 notes its version, after the response has gone, with one
 * write to the cache. It never reads anything during a request, and does
 * nothing at all when switched off.
 */
final class WebRuntime
{
    public const CACHE_KEY = 'skylock-reporter:web-runtime';

    private const ONE_IN = 500;

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

            // After the response, but still on the request's record in Nightwatch.
            NightwatchSampling::ignore(fn () => Cache::put(self::CACHE_KEY, ['version' => PHP_VERSION, 'sapi' => PHP_SAPI], now()->addDay()));
            self::$sampled = true;
        } catch (Throwable) {
            // The cache is down; the next sample will try again.
        }
    }

    /**
     * @return array{version: string, sapi: string}|null
     */
    public static function read(): ?array
    {
        try {
            $runtime = Cache::get(self::CACHE_KEY);
        } catch (Throwable) {
            return null;
        }

        return is_array($runtime) && is_string($runtime['version'] ?? null) && is_string($runtime['sapi'] ?? null)
            ? ['version' => $runtime['version'], 'sapi' => $runtime['sapi']]
            : null;
    }

    public static function forget(): void
    {
        self::$sampled = false;
    }
}
