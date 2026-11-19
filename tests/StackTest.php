<?php

use BlackAirplane\SkyLockReporter\Stack;
use BlackAirplane\SkyLockReporter\WebRuntime;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Process;

beforeEach(function () {
    $this->root = sys_get_temp_dir().'/skylock-stack-'.bin2hex(random_bytes(4));
    mkdir($this->root, recursive: true);
    Stack::$root = $this->root;
    WebRuntime::forget();
});

afterEach(function () {
    Stack::$root = '';
    exec('rm -rf '.escapeshellarg($this->root));
});

it('sends what it runs on as names from fixed lists, versions, counts and booleans', function () {
    if (PHP_OS_FAMILY !== 'Linux') {
        $this->markTestSkipped('Reading /etc is Linux only.');
    }
    ubuntuServer();
    config(['sentry.dsn' => 'https://sentinel-dsn@o1.ingest.sentry.io/1', 'skylock-reporter.commit' => str_repeat('ab', 20)]);

    $stack = Stack::collect();

    expect(array_keys($stack))->toBe(['schema', 'os', 'patching', 'php', 'installed', 'laravel', 'database', 'cache', 'deployedCommit', 'hosting', 'serverKey', 'errorMonitoring'])
        ->and($stack['os'])->toBe(['type' => 'linux', 'id' => 'ubuntu', 'version' => '22.04', 'container' => false])
        ->and($stack['patching'])->toBe(['securityUpdates' => 4, 'restartPending' => true, 'restartPendingSince' => '2026-10-30T02:05:00Z', 'restartForKernel' => true, 'kernel' => ['version' => '5.15.0-125.135', 'flavour' => 'generic'], 'autoUpdates' => true, 'extendedSupport' => false])
        ->and($stack['php']['cli'])->toBe(PHP_VERSION)
        ->and($stack['installed'])->toBe(['nginx' => ['version' => '1.28.1', 'packager' => 'nginx-org', 'running' => true]])
        ->and($stack['database'])->toMatchArray(['product' => 'sqlite', 'build' => null, 'provider' => 'local'])
        ->and($stack['deployedCommit'])->toBe(str_repeat('ab', 20))
        ->and($stack['serverKey'])->toBe(hash_hmac('sha256', 'skylock-reporter', '0123456789abcdef0123456789abcdef'))
        ->and($stack['errorMonitoring'])->toBeTrue()
        ->and(json_encode($stack))->not->toContain('sentinel-dsn')
        ->and(json_encode($stack))->not->toContain('0123456789abcdef0123456789abcdef')
        ->and(json_encode($stack))->not->toContain('Ubuntu 22.04.5 LTS')
        ->and(json_encode($stack))->not->toContain('linux-image')
        // Nothing of any other package, the client's own least of all.
        ->and(json_encode($stack))->not->toContain('sentinel')
        ->and(json_encode($stack))->not->toContain('adduser');
});

it('says since when a restart has been pending, and whether a new kernel waits on it, without naming a package', function () {
    ubuntuServer();

    expect(ubuntuPatching())
        ->restartPending->toBeTrue()
        ->restartPendingSince->toBe('2026-10-30T02:05:00Z')
        ->restartForKernel->toBeTrue()
        ->and(json_encode(ubuntuPatching()))->not->toContain('linux-');

    // libc and dbus ask for a restart too.
    serverFile('/var/run/reboot-required.pkgs', "libc6\ndbus\n");
    expect(ubuntuPatching()['restartForKernel'])->toBeFalse();

    // Without the package list, it can't say.
    unlink($this->root.'/var/run/reboot-required.pkgs');
    expect(ubuntuPatching())->restartPending->toBeTrue()->restartForKernel->toBeNull();
});

it('says no restart is needed only when it could look', function () {
    ubuntuServer();
    unlink($this->root.'/var/run/reboot-required');
    unlink($this->root.'/var/run/reboot-required.pkgs');

    expect(ubuntuPatching())
        ->restartPending->toBeFalse()
        ->restartPendingSince->toBeNull()
        ->restartForKernel->toBeNull();

    // open_basedir hides /var/run: that's "couldn't check", never "not needed".
    rmdir($this->root.'/var/run');
    expect(ubuntuPatching())
        ->restartPending->toBeNull()
        ->restartPendingSince->toBeNull()
        ->restartForKernel->toBeNull()
        ->securityUpdates->toBe(4);
});

it('says which kernel is running, from the line Ubuntu writes for it', function (string $signature, ?array $kernel) {
    ubuntuServer();
    serverFile('/proc/version_signature', $signature);

    expect(ubuntuPatching()['kernel'])->toBe($kernel);
})->with([
    'generic' => ["Ubuntu 5.15.0-125.135-generic 5.15.167\n", ['version' => '5.15.0-125.135', 'flavour' => 'generic']],
    'hardware enablement' => ["Ubuntu 6.8.0-48.48~22.04.1-generic 6.8.12\n", ['version' => '6.8.0-48.48~22.04.1', 'flavour' => 'generic']],
    'cloud' => ["Ubuntu 5.15.0-1056.61-aws 5.15.149\n", ['version' => '5.15.0-1056.61', 'flavour' => 'aws']],
    'lowlatency respin' => ["Ubuntu 6.8.0-147.147.1-lowlatency 6.8.12\n", ['version' => '6.8.0-147.147.1', 'flavour' => 'lowlatency']],
    'a custom kernel' => ["Linux 6.11.0-custom\n", null],
    'nothing to read' => ['', null],
]);

it('leaves the kernel to Ubuntu: Debian writes no build line', function () {
    ubuntuServer();

    expect(Closure::bind(fn (): ?array => Stack::patching('debian'), null, Stack::class)()['kernel'])->toBeNull();
});

it('leaves out what it can’t read instead of guessing', function () {
    $stack = Stack::collect();

    if (PHP_OS_FAMILY === 'Linux') {
        expect($stack['os']['id'])->toBeNull()
            ->and($stack['patching'])->toBeNull()
            ->and($stack['serverKey'])->toBeNull();
    }

    expect($stack['php']['web'])->toBeNull()
        ->and($stack['cache'])->toBeNull()
        ->and($stack['errorMonitoring'])->toBeFalse();
});

it('never runs a command', function () {
    Process::fake();
    ubuntuServer();

    Stack::collect();

    Process::assertNothingRan();
});

it('says where it runs from a fixed list, never a variable’s name or value', function () {
    config(['logging.channels.laravel-cloud-socket' => ['driver' => 'monolog']]);
    expect(Stack::collect()['hosting'])->toBe('laravel-cloud');

    putenv('RENDER=true');
    expect(Stack::collect()['hosting'])->toBe('render');
    putenv('RENDER');
});

it('notes the web PHP once, in a cache the scheduler can read, and never in a per-request store', function () {
    config(['cache.default' => 'array']);
    WebRuntime::sample(always: true);
    expect(WebRuntime::read())->toBeNull();

    config(['cache.default' => 'file']);
    WebRuntime::sample(always: true);
    expect(WebRuntime::read())->toMatchArray(['version' => PHP_VERSION, 'sapi' => PHP_SAPI]);

    Cache::forget(WebRuntime::CACHE_KEY);
    WebRuntime::sample(always: true);
    // Once per worker: a long-running worker doesn't write again.
    expect(WebRuntime::read())->toBeNull();
});

it('sends the stack only when SkyLock asks for it', function () {
    reporterConfigured();
    Http::fake([
        'skylock.example/api/ingest/health' => Http::sequence()
            ->push(['accepted' => true, 'scheduleKnown' => true, 'stackKnown' => false], 202)
            ->push(['accepted' => true, 'scheduleKnown' => true, 'stackKnown' => true], 202)
            ->push(['accepted' => true, 'scheduleKnown' => true], 202),
        'skylock.example/api/ingest/stack' => Http::response(['accepted' => true], 202),
    ]);

    $this->artisan('skylock:report')->assertSuccessful();
    $this->artisan('skylock:report')->assertSuccessful();
    // An older SkyLock that doesn't know about stacks is never sent one.
    $this->artisan('skylock:report')->assertSuccessful();

    Http::assertSentCount(4 + Http::recorded(fn (Request $request): bool => str_ends_with($request->url(), '/schedule'))->count());
    expect(Http::recorded(fn (Request $request): bool => str_ends_with($request->url(), '/stack')))->toHaveCount(1);
    Http::assertSent(fn (Request $request): bool => $request->url() === 'https://skylock.example/api/ingest/stack'
        && $request->method() === 'PUT'
        && $request['schema'] === 1
        && $request['php']['cli'] === PHP_VERSION);
    Http::assertSent(fn (Request $request): bool => str_ends_with($request->url(), '/health') && preg_match('/^[0-9a-f]{40}$/', (string) $request['stackHash']) === 1);
});

it('says every minute whether an error monitor is configured, and when it last read what the app runs on', function () {
    reporterConfigured();
    $this->travelTo(now()->setDate(2026, 11, 2)->setTime(12, 0)->utc());
    Http::fake(['*' => Http::response(['accepted' => true, 'scheduleKnown' => true, 'stackKnown' => true], 202)]);

    $this->artisan('skylock:report')->assertSuccessful();
    config(['nightwatch.token' => 'nw_secret_token']);
    $this->travelTo(now()->addMinute());
    $this->artisan('skylock:report')->assertSuccessful();

    $health = Http::recorded(fn (Request $request): bool => str_ends_with($request->url(), '/health'))->map(fn (array $pair) => $pair[0])->values();
    expect($health->map(fn (Request $request) => $request['errorMonitoring'])->all())->toBe([false, true])
        // What it runs on is still the copy read at 12:00; the monitor isn't.
        ->and($health->map(fn (Request $request) => $request['stackCollectedAt'])->unique()->values()->all())->toBe(['2026-11-02T12:00:00+00:00'])
        ->and($health->every(fn (Request $request): bool => ! str_contains($request->body(), 'nw_secret_token')))->toBeTrue();
});

it('reads what the app runs on again after a deploy', function () {
    reporterConfigured();
    $before = Stack::deployFingerprint();
    config(['skylock-reporter.commit' => str_repeat('a', 40)]);

    expect(Stack::deployFingerprint())->not->toBe($before)
        ->and(Stack::deployFingerprint())->toMatch('/^[0-9a-f]{40}$/');
});

it('reads what the app runs on again, and sends it, when SkyLock asks', function () {
    reporterConfigured();
    $this->travelTo(now()->setDate(2026, 11, 2)->setTime(12, 0)->utc());
    Http::fake([
        'skylock.example/api/ingest/health' => Http::sequence()
            ->push(['accepted' => true, 'scheduleKnown' => true, 'stackKnown' => true], 202)
            ->push(['accepted' => true, 'scheduleKnown' => true, 'stackKnown' => true, 'refreshStack' => true], 202),
        'skylock.example/api/ingest/stack' => Http::response(['accepted' => true], 202),
    ]);

    $this->artisan('skylock:report')->assertSuccessful();
    $this->travelTo(now()->addMinutes(5));
    $this->artisan('skylock:report')->assertSuccessful();

    $stacks = Http::recorded(fn (Request $request): bool => str_ends_with($request->url(), '/stack'));
    expect($stacks)->toHaveCount(1)
        ->and($stacks->first()[0]['collectedAt'])->toBe('2026-11-02T12:05:00+00:00')
        ->and($stacks->first()[0]['schema'])->toBe(1);
});

it('keeps each server’s note apart, so servers sharing a cache don’t take turns being reported', function () {
    config(['cache.default' => 'file']);
    // Nothing left from before servers kept their own; and two servers no earlier run has seen.
    cache()->forget(WebRuntime::CACHE_KEY);
    [$first, $second] = [bin2hex(random_bytes(16)), bin2hex(random_bytes(16))];
    serverFile('/etc/machine-id', $first."\n");
    WebRuntime::sample(always: true);
    expect(WebRuntime::read())->not->toBeNull();

    // Another server behind the same load balancer has noted nothing yet: it doesn't borrow the first's.
    serverFile('/etc/machine-id', $second."\n");
    expect(WebRuntime::read())->toBeNull();

    serverFile('/etc/machine-id', $first."\n");
    expect(WebRuntime::read())->toMatchArray(['version' => PHP_VERSION, 'sapi' => PHP_SAPI]);
});

it('says where a database or cache lives from a fixed list, never its host', function (mixed $host, string $provider) {
    expect(Stack::provider($host))->toBe($provider);
})->with([
    'this machine' => ['127.0.0.1', 'local'],
    'a socket' => ['/var/run/postgresql', 'local'],
    'no host at all' => [null, 'local'],
    'another server on the private network' => ['10.106.0.4', 'private-network'],
    'a Docker service' => ['mysql', 'private-network'],
    'Amazon RDS' => ['sentinel-prod.c9akciq32.us-east-1.rds.amazonaws.com', 'aws-rds'],
    'Neon' => ['ep-sentinel-123456.us-east-2.aws.neon.tech', 'neon'],
    'Upstash, as a URL' => ['tls://sentinel-cache.upstash.io', 'upstash'],
    'Laravel Cloud' => ['sentinel.pg.laravel.cloud', 'laravel-cloud'],
    'read and write hosts' => [['10.0.0.9', '10.0.0.10'], 'private-network'],
    'a public address' => ['203.0.113.7', 'other'],
    'anywhere else' => ['db.sentinel-client.com', 'other'],
]);

it('says who built the database from its own version, and only that', function (string $version, ?string $build) {
    $read = Closure::bind(fn (string $raw): ?string => Stack::build($raw), null, Stack::class);

    expect($read($version))->toBe($build);
})->with([
    'PGDG' => ['17.6 (Ubuntu 17.6-1.pgdg24.04+1)', 'pgdg'],
    'Ubuntu' => ['16.10 (Ubuntu 16.10-0ubuntu0.24.04.1)', 'ubuntu'],
    'Debian' => ['15.14 (Debian 15.14-0+deb12u1)', 'debian'],
    'MySQL from Ubuntu' => ['8.0.43-0ubuntu0.24.04.1', 'ubuntu'],
    'upstream' => ['17.6', null],
]);

/**
 * @return array{nginx: array{version: string, packager: string, running: bool|null}}|null
 */
function installedOn(string $packages): ?array
{
    serverFile('/var/lib/dpkg/status', $packages);

    return Closure::bind(fn (): ?array => Stack::installed(), null, Stack::class)();
}

it('says which nginx is installed and who packaged it, from the package manager’s own list', function (string $version, string $maintainer, array $installed) {
    // No nginx process to see here: whether it's the one running is its own question.
    expect(installedOn(packageList(packageEntry('nginx', $version, $maintainer))))->toBe(['nginx' => [...$installed, 'running' => null]]);
})->with([
    // The real entries, from Ubuntu 24.04's and nginx.org's packages.
    'nginx.org' => ['1.31.9-1~noble', 'NGINX Packaging <nginx-packaging@f5.com>', ['version' => '1.31.9', 'packager' => 'nginx-org']],
    'Ubuntu' => ['1.24.0-2ubuntu7.19', 'Ubuntu Developers <ubuntu-devel-discuss@lists.ubuntu.com>', ['version' => '1.24.0', 'packager' => 'ubuntu']],
    // Its version names the Ubuntu release it's for; it's still Ondřej's.
    'Ondřej’s PPA' => ['1.28.1-1+ubuntu24.04.1+deb.sury.org+1', 'Debian Nginx Maintainers <pkg-nginx-maintainers@alioth-lists.debian.net>', ['version' => '1.28.1', 'packager' => 'ondrej']],
    'Debian' => ['1.22.1-9+deb12u3', 'Debian Nginx Maintainers <pkg-nginx-maintainers@alioth-lists.debian.net>', ['version' => '1.22.1', 'packager' => 'debian']],
    'someone else’s build' => ['1:1.29.4-sentinel7', 'Sentinel Ops <ops@sentinel-client.example>', ['version' => '1.29.4', 'packager' => 'other']],
]);

it('counts only an nginx that’s fully installed, and nothing else in the list', function () {
    // After switching to nginx.org, Ubuntu's nginx-common stays listed: removed, its configuration kept.
    expect(installedOn(packageList(
        packageEntry('nginx-common', '1.24.0-2ubuntu7.19', 'Ubuntu Developers <ubuntu-devel-discuss@lists.ubuntu.com>', 'deinstall ok config-files'),
        packageEntry('nginx', '1.24.0-2ubuntu7.19', 'Ubuntu Developers <ubuntu-devel-discuss@lists.ubuntu.com>', 'deinstall ok config-files'),
        packageEntry('nginx-core', '1.30.7-1~noble', 'NGINX Packaging <nginx-packaging@f5.com>'),
    )))->toBe(['nginx' => ['version' => '1.30.7', 'packager' => 'nginx-org', 'running' => null]])
        // A package that only starts with its name isn't it.
        ->and(installedOn(packageList(packageEntry('nginx-sentinel-module', '9.9.9-1', 'Sentinel Ops <ops@sentinel-client.example>'))))->toBeNull()
        ->and(installedOn(packageList()))->toBeNull();

    // No list at all (not Debian or Ubuntu, or it can't be read): nothing to say.
    unlink($this->root.'/var/lib/dpkg/status');
    expect(Closure::bind(fn (): ?array => Stack::installed(), null, Stack::class)())->toBeNull();
});

it('finds nginx in a list of a few megabytes, past where every other read stops', function () {
    $padding = '';
    for ($i = 0; $i < 9000; $i++) {
        $padding .= packageEntry("libsentinel{$i}", '1.0-1', 'Ubuntu Developers <ubuntu-devel-discuss@lists.ubuntu.com>')."\n";
    }

    expect(strlen($padding))->toBeGreaterThan(2 * 1024 * 1024)
        ->and(installedOn($padding.packageEntry('nginx', '1.28.1-1~noble', 'NGINX Packaging <nginx-packaging@f5.com>')))->toBe(['nginx' => ['version' => '1.28.1', 'packager' => 'nginx-org', 'running' => null]]);
});

/*
 * Measured on Ubuntu 24.04, upgrading Ubuntu's nginx 1.24.0 to nginx.org's
 * 1.31.9: after the upgrade `nginx -v` says 1.31.9 while 1.24.0 still
 * answers; a reload changes nothing; a restart writes the process id file
 * again. Its time against the program's says which is running.
 */
it('says whether the nginx running is the one installed, from two file times', function () {
    $running = fn (): ?bool => installedOn(packageList(packageEntry('nginx', '1.31.9-1~noble', 'NGINX Packaging <nginx-packaging@f5.com>')))['nginx']['running'];

    // Upgraded just now; nginx has been running since an hour ago: still the old one.
    serverFile('/usr/sbin/nginx', 'the program');
    serverFile('/run/nginx.pid', "2197\n");
    touch($this->root.'/run/nginx.pid', time() - 3600);
    expect($running())->toBeFalse();

    // Restarted: nginx writes its process id file again.
    touch($this->root.'/run/nginx.pid', time() + 5);
    expect($running())->toBeTrue();

    // Stopped, or it keeps its process id somewhere else: nothing to say.
    unlink($this->root.'/run/nginx.pid');
    expect($running())->toBeNull();
});

it('reads what the app runs on again when nginx restarts', function () {
    serverFile('/run/nginx.pid', "2197\n");
    touch($this->root.'/run/nginx.pid', strtotime('2026-11-18 06:25:00 UTC'));
    $before = Stack::deployFingerprint();

    touch($this->root.'/run/nginx.pid', strtotime('2026-11-19 01:43:00 UTC'));

    expect(Stack::deployFingerprint())->not->toBe($before);
});

it('reads what the app runs on again when the server’s packages change', function () {
    serverFile('/var/lib/dpkg/status', packageList());
    touch($this->root.'/var/lib/dpkg/status', strtotime('2026-11-18 06:25:00 UTC'));
    $before = Stack::deployFingerprint();

    // An upgrade, unattended or by hand, writes the list again.
    touch($this->root.'/var/lib/dpkg/status', strtotime('2026-11-19 06:25:00 UTC'));

    expect(Stack::deployFingerprint())->not->toBe($before);
});

it('leaves out a note of which PHP served a request once the server’s packages have changed since', function () {
    if (PHP_OS_FAMILY !== 'Linux') {
        $this->markTestSkipped('Reading /etc is Linux only.');
    }
    ubuntuServer();
    config(['cache.default' => 'file']);
    touch($this->root.'/var/lib/dpkg/status', time() - 3600);
    WebRuntime::sample(always: true);
    expect(Stack::collect()['php'])->toBe(['cli' => PHP_VERSION, 'web' => PHP_VERSION, 'webSapi' => PHP_SAPI]);

    // PHP was upgraded after that request: the note is about the one before.
    touch($this->root.'/var/lib/dpkg/status', time() + 60);
    expect(Stack::collect()['php'])->toBe(['cli' => PHP_VERSION, 'web' => null, 'webSapi' => null]);
});
