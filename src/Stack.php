<?php

namespace BlackAirplane\SkyLockReporter;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Redis;
use Throwable;

/**
 * What this app runs on: the operating system and whether it's waiting on
 * security updates or a restart, the PHP running the scheduler and serving
 * web requests and the web server in front of it (which nginx is installed,
 * and which is serving), Laravel, the database and Redis versions and where
 * each lives, the deployed commit, the hosting, and whether an error monitor
 * is configured.
 *
 * It only reads plain files and asks PHP and the app's own connections; it
 * never runs a command. It sends names from fixed lists, versions, counts
 * and booleans: never a hostname, an address, a connection name or an
 * environment value. Where a database lives is worked out here, from its
 * host, into a name from a fixed list; the host never leaves the app.
 * Anything it can't read is sent as null.
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
     * Where a database or cache lives, by the end of its host's name: the
     * provider runs and patches it. A host that matches none of these is
     * `other`; one on this machine is `local`; one on a private network
     * (an address like 10.0.0.5, or a one-word name like `mysql` from
     * Docker) is `private-network`, most likely another server we manage.
     */
    private const PROVIDERS = [
        '.rds.amazonaws.com' => 'aws-rds',
        '.cache.amazonaws.com' => 'aws-elasticache',
        '.neon.tech' => 'neon',
        '.upstash.io' => 'upstash',
        '.db.ondigitalocean.com' => 'digitalocean',
        '.laravel.cloud' => 'laravel-cloud',
        '.supabase.co' => 'supabase',
        '.supabase.com' => 'supabase',
        '.psdb.cloud' => 'planetscale',
        '.redis-cloud.com' => 'redis-cloud',
        '.redislabs.com' => 'redis-cloud',
        '.database.azure.com' => 'azure',
        '.redis.cache.windows.net' => 'azure',
    ];

    /** The package manager's own list of what's installed, on Debian and Ubuntu. */
    private const PACKAGES = '/var/lib/dpkg/status';

    /** That list is a few megabytes on a busy server; every other file read stops at 64 KB. */
    private const PACKAGES_BYTES = 16 * 1024 * 1024;

    /**
     * The only packages ever looked for in it, each under the product it
     * means. No other package's name or version leaves the server.
     */
    private const INSTALLED = [
        'nginx' => ['nginx', 'nginx-core', 'nginx-full', 'nginx-light', 'nginx-extras'],
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
        $server = is_array($os) && $os['container'] === false && in_array($os['id'], ['ubuntu', 'debian'], true);
        // A note of which web server was serving, from before the server's packages last changed,
        // says nothing about which one serves now: an upgrade and a restart may both have happened since.
        $changed = $server ? self::packagesChangedAt() : null;
        $serving = $web !== null && ($changed === null || $web['at'] >= $changed) ? $web['server'] : null;

        return [
            'schema' => 1,
            'os' => $os,
            'patching' => is_array($os) && $os['container'] === false ? self::attempt(fn (): ?array => self::patching($os['id'])) : null,
            'php' => [
                'cli' => PHP_VERSION,
                'web' => $web['version'] ?? null,
                'webSapi' => $web['sapi'] ?? null,
            ],
            'webServer' => $serving,
            'installed' => $server ? self::attempt(self::installed(...)) : null,
            'laravel' => app()->version(),
            'database' => self::attempt(self::database(...)),
            'cache' => self::attempt(self::redis(...)),
            'deployedCommit' => self::attempt(self::commit(...)),
            'hosting' => self::attempt(self::hosting(...)),
            'serverKey' => is_array($os) && $os['container'] === false ? self::serverKey() : null,
            'errorMonitoring' => self::attempt(self::errorMonitoring(...)),
        ];
    }

    /**
     * Changes with every deploy, and whenever the server's packages change
     * (an upgrade, unattended or not), so what the app runs on is read again
     * straight after either rather than up to an hour later. Only ever part
     * of a cache key: none of it leaves the app.
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
            self::packagesChangedAt(),
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
     * installing them (since when, and whether it's for a new kernel),
     * which kernel is running (Ubuntu), whether security updates install
     * themselves, and whether Ubuntu Pro's extended support is on. Other
     * systems: null.
     *
     * @return array{securityUpdates: int|null, restartPending: bool|null, restartPendingSince: string|null, restartForKernel: bool|null, kernel: array{version: string, flavour: string}|null, autoUpdates: bool, extendedSupport: bool}|null
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
            ...self::restart(),
            'kernel' => $id === 'ubuntu' ? self::kernel() : null,
            'autoUpdates' => preg_match('/APT::Periodic::Unattended-Upgrade\s+"1"/', (string) self::read('/etc/apt/apt.conf.d/20auto-upgrades')) === 1,
            'extendedSupport' => self::exists('/etc/apt/sources.list.d/ubuntu-esm-infra.sources') || self::exists('/etc/apt/sources.list.d/ubuntu-esm-infra.list'),
        ];
    }

    /**
     * The running kernel's build and flavour, from the line Ubuntu writes for
     * exactly this: "Ubuntu 5.15.0-125.135-generic 5.15.167" becomes
     * 5.15.0-125.135 and generic. SkyLock looks up what that build is missing.
     * Anything else (a custom kernel, a file it can't read) is null.
     *
     * @return array{version: string, flavour: string}|null
     */
    private static function kernel(): ?array
    {
        $signature = trim((string) self::read('/proc/version_signature'));
        $pattern = '/^Ubuntu (\d+\.\d+\.\d+-\d+\.\d+(?:\.\d+)?(?:~\d+\.\d+(?:\.\d+)?)?)-([a-z][a-z0-9]*(?:-[a-z0-9]+)*) \d/';

        if (preg_match($pattern, $signature, $match) !== 1 || strlen($match[1]) > 40 || strlen($match[2]) > 30) {
            return null;
        }

        return ['version' => $match[1], 'flavour' => $match[2]];
    }

    /**
     * Whether a restart is pending (the flag file Debian and Ubuntu write),
     * when the flag was last written, and whether a new kernel is among the
     * packages waiting on it. Every install that needs a restart writes the
     * flag again, so the time means "pending since at least". Only a yes or
     * no about the kernel leaves the server, never the package names.
     *
     * Null when it can't look: open_basedir hides /var/run, and "couldn't
     * check" must never read as "no restart needed".
     *
     * @return array{restartPending: bool|null, restartPendingSince: string|null, restartForKernel: bool|null}
     */
    private static function restart(): array
    {
        if (! @is_dir(self::$root.'/var/run')) {
            return ['restartPending' => null, 'restartPendingSince' => null, 'restartForKernel' => null];
        }

        $flag = self::$root.'/var/run/reboot-required';
        if (! @file_exists($flag)) {
            return ['restartPending' => false, 'restartPendingSince' => null, 'restartForKernel' => null];
        }

        $written = @filemtime($flag);
        $packages = self::read('/var/run/reboot-required.pkgs');

        return [
            'restartPending' => true,
            'restartPendingSince' => $written === false ? null : gmdate('Y-m-d\TH:i:s\Z', $written),
            'restartForKernel' => $packages === null ? null : preg_match('/^linux-(?:image|modules|base)\b/m', $packages) === 1,
        ];
    }

    /**
     * The default connection's server, as a product from a fixed list and its
     * version; who built it, when the version says (`pgdg`, `ubuntu`,
     * `debian`: PostgreSQL says "17.6 (Ubuntu 17.6-1.pgdg24.04+1)"); and where
     * it lives, from its host, as a provider from a fixed list.
     *
     * @return array{product: string, version: string, build: string|null, provider: string}|null
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
        if ($product === null || preg_match($pattern, $raw, $match) !== 1) {
            return null;
        }

        $config = (array) config('database.connections.'.$connection->getName());

        return [
            'product' => $product,
            'version' => $match[1],
            'build' => self::build($raw),
            'provider' => $product === 'sqlite' ? 'local' : self::provider($config['host'] ?? null, $config['url'] ?? null, $config['unix_socket'] ?? null),
        ];
    }

    /**
     * Who built a server, from what its version says about itself: PGDG's
     * packages, or a distribution's. Only these words, or nothing.
     */
    private static function build(string $version): ?string
    {
        return match (true) {
            preg_match('/pgdg/i', $version) === 1 => 'pgdg',
            preg_match('/ubuntu/i', $version) === 1 => 'ubuntu',
            preg_match('/debian|\+deb\d/i', $version) === 1 => 'debian',
            default => null,
        };
    }

    /**
     * Where a database or cache lives, from its host, as a name from a fixed
     * list. The host is read here and goes no further.
     */
    public static function provider(mixed $host, mixed $url = null, mixed $socket = null): string
    {
        if (is_array($host)) {
            $host = $host[0] ?? null;
        }
        if ((! is_string($host) || $host === '') && is_string($url) && $url !== '') {
            $host = parse_url($url, PHP_URL_HOST);
        }
        if (is_string($host) && str_contains($host, '://')) {
            $host = parse_url($host, PHP_URL_HOST);
        }
        $host = is_string($host) ? strtolower(trim($host, " []\t\n")) : '';

        if ($host === '' || in_array($host, ['localhost', '127.0.0.1', '::1'], true) || str_starts_with($host, '/') || (is_string($socket) && $socket !== '')) {
            return 'local';
        }
        if (filter_var($host, FILTER_VALIDATE_IP) !== false) {
            return filter_var($host, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE) === false ? 'private-network' : 'other';
        }
        if (! str_contains($host, '.')) {
            return 'private-network';
        }

        foreach (self::PROVIDERS as $suffix => $provider) {
            if (str_ends_with($host, $suffix)) {
                return $provider;
            }
        }

        return 'other';
    }

    /**
     * Redis or Valkey, only when the app's cache, queue or sessions use it,
     * and where it lives, as a provider from a fixed list.
     *
     * @return array{product: string, version: string, provider: string}|null
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

        $config = (array) config('database.redis.default');

        return $version !== null && preg_match('/^\d+(?:\.\d+){0,2}$/', $version) === 1
            ? ['product' => $valkey !== null ? 'valkey' : 'redis', 'version' => $version, 'provider' => self::provider($config['host'] ?? null, $config['url'] ?? null, $config['path'] ?? null)]
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
     * Which nginx is installed and who packaged it, from the package
     * manager's own list: a plain file, read by the scheduler, so it needs no
     * visitor and works on a site nobody has opened in days. Only nginx's
     * entry is looked at, and only once it's fully installed (a package
     * removed with its configuration kept stays in the list). What leaves
     * is the upstream version (`1.28.1`, without the packaging revision)
     * and the packager, from a fixed list: `nginx-org`, `ondrej`, `ubuntu`,
     * `debian` or `other`. A distribution's own build carries fixes its
     * version doesn't show, so who packaged it decides how it's judged.
     *
     * @return array{nginx: array{version: string, packager: string}}|null
     */
    private static function installed(): ?array
    {
        $list = self::readPath(self::$root.self::PACKAGES, self::PACKAGES_BYTES);
        if ($list === null) {
            return null;
        }

        $installed = [];
        foreach (self::INSTALLED as $product => $packages) {
            foreach ($packages as $package) {
                $entry = self::packageEntry($list, $package);
                if ($entry !== null) {
                    $installed[$product] = $entry;
                    break;
                }
            }
        }

        return isset($installed['nginx']) ? ['nginx' => $installed['nginx']] : null;
    }

    /**
     * @return array{version: string, packager: string}|null
     */
    private static function packageEntry(string $list, string $package): ?array
    {
        if (preg_match('/^Package: '.preg_quote($package, '/').'\n(.*?)(?:\n\n|\z)/ms', $list, $entry) !== 1
            || preg_match('/^Status: install ok installed$/m', $entry[1]) !== 1
            || preg_match('/^Version: (?:\d+:)?(\d+(?:\.\d+){1,3})(?:[-+~]\S*)?$/m', $entry[1], $version) !== 1) {
            return null;
        }

        $maintainer = preg_match('/^Maintainer: (.*)$/m', $entry[1], $match) === 1 ? strtolower($match[1]) : '';
        $revision = preg_match('/^Version: (\S+)$/m', $entry[1], $match) === 1 ? strtolower($match[1]) : '';

        return [
            'version' => $version[1],
            'packager' => match (true) {
                // Ondřej Surý's PPA builds say so in the version, which also names the Ubuntu release they're for.
                str_contains($revision, 'sury.org') || str_contains($maintainer, 'sury') => 'ondrej',
                str_contains($maintainer, 'nginx packaging') || str_contains($maintainer, '@f5.com') || str_contains($maintainer, '@nginx.') => 'nginx-org',
                str_contains($maintainer, 'ubuntu') => 'ubuntu',
                str_contains($maintainer, 'debian') => 'debian',
                default => 'other',
            },
        ];
    }

    /**
     * When the server's packages last changed: when its package manager
     * last wrote its list. Null when there's no such list to see.
     */
    private static function packagesChangedAt(): ?int
    {
        $path = self::$root.self::PACKAGES;
        $changed = @is_file($path) ? @filemtime($path) : false;

        return $changed === false ? null : $changed;
    }

    /**
     * The same for every app on this server and different on every other,
     * without the machine id itself leaving it (systemd's app-specific id).
     * Null when it can't be read.
     */
    public static function serverKey(): ?string
    {
        return self::attempt(self::machineKey(...));
    }

    private static function machineKey(): ?string
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

    /**
     * @param  int<1, max>  $bytes  the most it reads of the file
     */
    private static function readPath(string $path, int $bytes = 65536): ?string
    {
        // open_basedir and permissions can say no: that's "couldn't measure", never a failure.
        $contents = @is_file($path) && @is_readable($path) ? @file_get_contents($path, length: $bytes) : false;

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
