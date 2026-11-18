<?php

namespace BlackAirplane\SkyLockReporter;

use Illuminate\Contracts\Foundation\Application;
use Illuminate\Support\Facades\Cache;
use Throwable;

/**
 * Which PHP serves web requests, and which web server hands them to it. The
 * scheduler's command-line PHP can be a different version (a server with
 * several installed), and only a web request knows its web server: nginx
 * passes its own version to PHP-FPM with every request (`SERVER_SOFTWARE`,
 * from fastcgi_params), even when it hides it from visitors. So about one
 * web request in 500 notes both, after the response has gone, with one
 * write to the cache. It never reads anything during a request, and does
 * nothing at all when switched off.
 *
 * Each server keeps its own note (by its server key), so servers behind one
 * load balancer that share a cache never take turns at being the one
 * reported; a note lasts a week, so a quiet app keeps it between samples.
 */
final class WebRuntime
{
    public const CACHE_KEY = 'skylock-reporter:web-runtime';

    private const ONE_IN = 500;

    private const KEEP_DAYS = 7;

    /** Web servers, by how they name themselves to PHP: only these are ever sent. */
    private const WEB_SERVERS = [
        'nginx' => 'nginx',
        'apache' => 'apache',
        'caddy' => 'caddy',
        'litespeed' => 'litespeed',
        'openresty' => 'openresty',
    ];

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

            $note = ['version' => PHP_VERSION, 'sapi' => PHP_SAPI, 'server' => self::webServer()];
            // After the response, but still on the request's record in Nightwatch.
            NightwatchSampling::ignore(fn () => Cache::put(self::key(), $note, now()->addDays(self::KEEP_DAYS)));
            self::$sampled = true;
        } catch (Throwable) {
            // The cache is down; the next sample will try again.
        }
    }

    /**
     * This server's note: the web PHP's version and SAPI, and the web server
     * when one said who it is.
     *
     * @return array{version: string, sapi: string, server: array{product: string, version: string|null, build: string|null}|null}|null
     */
    public static function read(): ?array
    {
        try {
            $runtime = Cache::get(self::key()) ?? Cache::get(self::CACHE_KEY);
        } catch (Throwable) {
            return null;
        }

        // The cache is shared with the app, so what comes back is checked like anything else read:
        // a version and a SAPI name in the characters those have, a web server from the list, or nothing.
        if (! is_array($runtime)
            || ! is_string($runtime['version'] ?? null) || preg_match('/^[0-9A-Za-z.+\-]{1,32}$/', $runtime['version']) !== 1
            || ! is_string($runtime['sapi'] ?? null) || preg_match('/^[a-z0-9._-]{1,32}$/', $runtime['sapi']) !== 1) {
            return null;
        }

        $server = is_array($runtime['server'] ?? null) ? $runtime['server'] : null;
        $valid = $server !== null
            && in_array($server['product'] ?? null, self::WEB_SERVERS, true)
            && ($server['version'] === null || (is_string($server['version']) && preg_match('/^\d+(?:\.\d+){0,3}$/', $server['version']) === 1))
            && in_array($server['build'] ?? null, [null, 'ubuntu', 'debian'], true);

        return [
            'version' => $runtime['version'],
            'sapi' => $runtime['sapi'],
            'server' => $valid ? ['product' => (string) $server['product'], 'version' => $server['version'], 'build' => $server['build'] ?? null] : null,
        ];
    }

    public static function forget(): void
    {
        self::$sampled = false;
    }

    /**
     * The web server that handed PHP this request, from its own name for
     * itself (`nginx/1.28.1`, `Apache/2.4.58 (Ubuntu)`): a product from a
     * fixed list, its version, and whether a distribution built it. Nothing
     * else in that value ever leaves the app.
     *
     * @return array{product: string, version: string|null, build: string|null}|null
     */
    private static function webServer(): ?array
    {
        $software = request()->server('SERVER_SOFTWARE');
        if (! is_string($software) || preg_match('#^([A-Za-z]+)(?:/v?(\d+(?:\.\d+){0,3}))?(?:[^(]*\((Ubuntu|Debian)\))?#', $software, $match) !== 1) {
            return null;
        }

        $product = self::WEB_SERVERS[strtolower($match[1])] ?? null;

        return $product === null ? null : [
            'product' => $product,
            'version' => ($match[2] ?? '') !== '' ? $match[2] : null,
            'build' => isset($match[3]) ? strtolower($match[3]) : null,
        ];
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
