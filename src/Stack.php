<?php

namespace BlackAirplane\SkyLockReporter;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Redis;
use Throwable;

/**
 * What this app runs on: the operating system and whether it's waiting on
 * security updates or a restart, the PHP running the scheduler and serving
 * web requests, Laravel, the database and Redis versions, the deployed
 * commit, the hosting, and whether an error monitor is configured.
 *
 * It only reads plain files and asks PHP and the app's own connections; it
 * never runs a command. It sends names from fixed lists, versions, counts
 * and booleans: never a hostname, an address, a connection name or an
 * environment value. Anything it can't read is sent as null.
 */
final class Stack
{
    /** The filesystem root it reads from; tests point it somewhere else. */
    public static string $root = '';

    /**
     * Error monitors, and the config each one is configured by. Only whether
     * it's set leaves the app.
     */
    private const ERROR_MONITORS = [
        'sentry.dsn',
        'bugsnag.api_key',
        'flare.key',
        'honeybadger.api_key',
        'logging.channels.rollbar.access_token',
        'nightwatch.token',
    ];

    /**
     * Platforms, from variables only they set (their names, never their values).
     */
    private const HOSTING_VARIABLES = [
        'VAPOR_ARTIFACT_NAME' => 'vapor',
        'AWS_LAMBDA_FUNCTION_NAME' => 'aws-lambda',
        'DYNO' => 'heroku',
        'RENDER' => 'render',
        'FLY_APP_NAME' => 'fly',
        'K_SERVICE' => 'cloud-run',
        'WEBSITE_SITE_NAME' => 'azure-app-service',
        'RAILWAY_ENVIRONMENT' => 'railway',
        'PLATFORM_APPLICATION_NAME' => 'platform-sh',
    ];

    /**
     * @return array<string, mixed>
     */
    public static function collect(): array
    {
        $os = self::attempt(self::os(...));
        $web = WebRuntime::read();

        return [
            'schema' => 1,
            'os' => $os,
            'patching' => is_array($os) && $os['container'] === false ? self::attempt(fn (): ?array => self::patching($os['id'])) : null,
            'php' => [
                'cli' => PHP_VERSION,
                'web' => $web['version'] ?? null,
                'webSapi' => $web['sapi'] ?? null,
            ],
            'laravel' => app()->version(),
            'database' => self::attempt(self::database(...)),
            'cache' => self::attempt(self::redis(...)),
            'deployedCommit' => self::attempt(self::commit(...)),
            'hosting' => self::attempt(self::hosting(...)),
            'serverKey' => is_array($os) && $os['container'] === false ? self::attempt(self::serverKey(...)) : null,
            'errorMonitoring' => self::attempt(self::errorMonitoring(...)),
        ];
    }

    /**
     * Changes with every deploy, so what the app runs on is read again after
     * one rather than up to an hour later. Only ever part of a cache key:
     * none of it leaves the app.
     */
    public static function deployFingerprint(): string
    {
        $mtime = fn (string $path): int|false => is_file($path) ? filemtime($path) : false;

        return sha1((string) json_encode([
            self::attempt(self::commit(...)),
            getenv('LARAVEL_CLOUD_DEPLOY_UUID'),
            getenv('FORGE_DEPLOY_COMMIT'),
            getenv('VAPOR_COMMIT_HASH'),
            // A deploy builds the dependencies and caches the config again,
            // and a changed environment variable means a new deploy.
            $mtime(base_path('vendor/composer/installed.php')),
            $mtime(app()->getCachedConfigPath()),
        ]));
    }

    /**
     * @return array{type: string, id: string|null, version: string|null, container: bool}
     */
    private static function os(): array
    {
        $type = strtolower(PHP_OS_FAMILY);
        if ($type !== 'linux') {
            return ['type' => $type, 'id' => null, 'version' => null, 'container' => false];
        }

        $release = self::keyValues((string) self::read('/etc/os-release'));
        $id = strtolower($release['ID'] ?? '');
        $version = $release['VERSION_ID'] ?? '';
        $cgroup = (string) self::read('/proc/1/cgroup');

        return [
            'type' => 'linux',
            'id' => preg_match('/^[a-z0-9._-]{1,32}$/', $id) === 1 ? $id : null,
            'version' => preg_match('/^[0-9][0-9.]{0,15}$/', $version) === 1 ? $version : null,
            'container' => self::exists('/.dockerenv') || preg_match('/docker|kubepods|containerd|libpod/', $cgroup) === 1 || getenv('KUBERNETES_SERVICE_HOST') !== false,
        ];
    }

    /**
     * Debian and Ubuntu only: pending security updates (from the count
     * update-notifier keeps), whether a restart is needed to finish
     * installing them, whether security updates install themselves, and
     * whether Ubuntu Pro's extended support is on. Other systems: null.
     *
     * @return array{securityUpdates: int|null, restartPending: bool, autoUpdates: bool, extendedSupport: bool}|null
     */
    private static function patching(?string $id): ?array
    {
        if (! in_array($id, ['ubuntu', 'debian'], true)) {
            return null;
        }

        $notice = self::read('/var/lib/update-notifier/updates-available');
        $security = null;
        if ($notice !== null) {
            $security = preg_match('/(\d+)\s+(?:of these\s+)?updates?\s+(?:are|is)\s+(?:standard\s+)?security updates?/i', $notice, $match) === 1 ? (int) $match[1] : 0;
        }

        return [
            'securityUpdates' => $security,
            'restartPending' => self::exists('/var/run/reboot-required'),
            'autoUpdates' => preg_match('/APT::Periodic::Unattended-Upgrade\s+"1"/', (string) self::read('/etc/apt/apt.conf.d/20auto-upgrades')) === 1,
            'extendedSupport' => self::exists('/etc/apt/sources.list.d/ubuntu-esm-infra.sources') || self::exists('/etc/apt/sources.list.d/ubuntu-esm-infra.list'),
        ];
    }

    /**
     * The default connection's server, as a product from a fixed list and its version.
     *
     * @return array{product: string, version: string}|null
     */
    private static function database(): ?array
    {
        $connection = DB::connection();
        $raw = (string) $connection->getServerVersion();

        $product = match ($connection->getDriverName()) {
            'mysql', 'mariadb' => str_contains(strtolower($raw), 'mariadb') ? 'mariadb' : 'mysql',
            'pgsql' => 'postgresql',
            'sqlite' => 'sqlite',
            default => null,
        };
        // MariaDB can say "5.5.5-10.11.8-MariaDB": the real version is the one before "-MariaDB".
        $pattern = $product === 'mariadb' ? '/(\d+\.\d+(?:\.\d+)?)-MariaDB/i' : '/^(\d+(?:\.\d+){0,2})/';

        return $product !== null && preg_match($pattern, $raw, $match) === 1 ? ['product' => $product, 'version' => $match[1]] : null;
    }

    /**
     * Redis or Valkey, only when the app's cache, queue or sessions use it.
     *
     * @return array{product: string, version: string}|null
     */
    private static function redis(): ?array
    {
        $uses = in_array('redis', [
            config('cache.stores.'.config('cache.default').'.driver'),
            config('queue.connections.'.config('queue.default').'.driver'),
            config('session.driver'),
        ], true);
        if (! $uses) {
            return null;
        }

        $info = Redis::connection()->command('info', ['server']);
        $server = is_array($info) ? ($info['Server'] ?? $info) : [];
        $valkey = is_string($server['valkey_version'] ?? null) ? $server['valkey_version'] : null;
        $version = $valkey ?? (is_string($server['redis_version'] ?? null) ? $server['redis_version'] : null);

        return $version !== null && preg_match('/^\d+(?:\.\d+){0,2}$/', $version) === 1
            ? ['product' => $valkey !== null ? 'valkey' : 'redis', 'version' => $version]
            : null;
    }

    /**
     * The commit that's deployed: SKYLOCK_COMMIT, a platform's own variable,
     * or the git checkout the app runs from.
     */
    private static function commit(): ?string
    {
        $candidates = [
            config('skylock-reporter.commit'),
            getenv('VERCEL_GIT_COMMIT_SHA'),
            getenv('RENDER_GIT_COMMIT'),
            getenv('HEROKU_SLUG_COMMIT'),
            getenv('SOURCE_VERSION'),
            self::gitHead(),
        ];

        foreach ($candidates as $candidate) {
            if (is_string($candidate) && preg_match('/^[0-9a-f]{40}$/', strtolower(trim($candidate))) === 1) {
                return strtolower(trim($candidate));
            }
        }

        return null;
    }

    private static function gitHead(): ?string
    {
        $git = base_path('.git');
        $head = trim((string) self::readPath($git.'/HEAD'));
        if (! str_starts_with($head, 'ref: ')) {
            return $head === '' ? null : $head;
        }

        $ref = substr($head, 5);
        if (preg_match('#^refs/[A-Za-z0-9._/-]+$#', $ref) !== 1) {
            return null;
        }

        $loose = self::readPath($git.'/'.$ref);
        if ($loose !== null) {
            return trim($loose);
        }

        foreach (explode("\n", (string) self::readPath($git.'/packed-refs')) as $line) {
            if (str_ends_with($line, ' '.$ref)) {
                return strtok($line, ' ') ?: null;
            }
        }

        return null;
    }

    /**
     * Where it runs, from a fixed list: worked out here, so no variable name leaves the app.
     */
    private static function hosting(): ?string
    {
        foreach (self::HOSTING_VARIABLES as $variable => $hosting) {
            if (getenv($variable) !== false) {
                return $hosting;
            }
        }

        if (array_key_exists('laravel-cloud-socket', (array) config('logging.channels'))) {
            return 'laravel-cloud';
        }

        return match (true) {
            str_starts_with(base_path(), '/home/forge/') => 'forge',
            str_starts_with(base_path(), '/home/ploi/') => 'ploi',
            default => null,
        };
    }

    /**
     * The same for every app on this server and different on every other,
     * without the machine id itself leaving it (systemd's app-specific id).
     */
    private static function serverKey(): ?string
    {
        $machine = trim((string) self::read('/etc/machine-id'));

        return preg_match('/^[0-9a-f]{32}$/', $machine) === 1 ? hash_hmac('sha256', 'skylock-reporter', $machine) : null;
    }

    /**
     * Whether an error monitor is configured: a few config lookups, so the
     * minute report asks it every time and a deploy that adds one shows
     * within a minute.
     */
    public static function errorMonitoring(): bool
    {
        foreach (self::ERROR_MONITORS as $key) {
            $value = config($key);
            if (is_string($value) && trim($value) !== '') {
                return true;
            }
        }

        return false;
    }

    /**
     * @return array<string, string>
     */
    private static function keyValues(string $contents): array
    {
        $values = [];
        foreach (explode("\n", $contents) as $line) {
            if (preg_match('/^([A-Z_]+)=(.*)$/', trim($line), $match) === 1) {
                $values[$match[1]] = trim($match[2], "\"' ");
            }
        }

        return $values;
    }

    private static function read(string $path): ?string
    {
        return self::readPath(self::$root.$path);
    }

    private static function readPath(string $path): ?string
    {
        // open_basedir and permissions can say no: that's "couldn't measure", never a failure.
        $contents = @is_file($path) && @is_readable($path) ? @file_get_contents($path, length: 65536) : false;

        return $contents === false ? null : $contents;
    }

    private static function exists(string $path): bool
    {
        return @file_exists(self::$root.$path);
    }

    /**
     * @template T
     *
     * @param  callable(): T  $read
     * @return T|null
     */
    private static function attempt(callable $read): mixed
    {
        try {
            return $read();
        } catch (Throwable) {
            return null;
        }
    }
}
