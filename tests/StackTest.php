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

    expect(array_keys($stack))->toBe(['schema', 'os', 'patching', 'php', 'laravel', 'database', 'cache', 'deployedCommit', 'hosting', 'serverKey', 'errorMonitoring'])
        ->and($stack['os'])->toBe(['type' => 'linux', 'id' => 'ubuntu', 'version' => '22.04', 'container' => false])
        ->and($stack['patching'])->toBe(['securityUpdates' => 4, 'restartPending' => true, 'restartPendingSince' => '2026-10-30T02:05:00Z', 'restartForKernel' => true, 'kernel' => ['version' => '5.15.0-125.135', 'flavour' => 'generic'], 'autoUpdates' => true, 'extendedSupport' => false])
        ->and($stack['php']['cli'])->toBe(PHP_VERSION)
        ->and($stack['database']['product'])->toBe('sqlite')
        ->and($stack['deployedCommit'])->toBe(str_repeat('ab', 20))
        ->and($stack['serverKey'])->toBe(hash_hmac('sha256', 'skylock-reporter', '0123456789abcdef0123456789abcdef'))
        ->and($stack['errorMonitoring'])->toBeTrue()
        ->and(json_encode($stack))->not->toContain('sentinel-dsn')
        ->and(json_encode($stack))->not->toContain('0123456789abcdef0123456789abcdef')
        ->and(json_encode($stack))->not->toContain('Ubuntu 22.04.5 LTS')
        ->and(json_encode($stack))->not->toContain('linux-image');
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
    expect(WebRuntime::read())->toBe(['version' => PHP_VERSION, 'sapi' => PHP_SAPI, 'server' => null]);

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

it('notes which web server handed PHP the request, from a fixed list, with nothing else from that value', function (?string $software, ?array $server) {
    config(['cache.default' => 'file']);
    request()->server->set('SERVER_SOFTWARE', $software);

    WebRuntime::sample(always: true);

    expect(WebRuntime::read()['server'])->toBe($server);
})->with([
    // nginx passes its own version to PHP-FPM even with server_tokens off.
    'nginx' => ['nginx/1.28.1', ['product' => 'nginx', 'version' => '1.28.1', 'build' => null]],
    'Apache, built by Ubuntu' => ['Apache/2.4.58 (Ubuntu)', ['product' => 'apache', 'version' => '2.4.58', 'build' => 'ubuntu']],
    'Caddy' => ['Caddy/v2.10.0', ['product' => 'caddy', 'version' => '2.10.0', 'build' => null]],
    'LiteSpeed, no version' => ['LiteSpeed', ['product' => 'litespeed', 'version' => null, 'build' => null]],
    'something not on the list' => ['sentinel-proxy/9.9', null],
    'nothing' => [null, null],
]);

it('keeps each server’s note apart, so servers sharing a cache don’t take turns being reported', function () {
    config(['cache.default' => 'file']);
    serverFile('/etc/machine-id', str_repeat('a', 32)."\n");
    request()->server->set('SERVER_SOFTWARE', 'nginx/1.30.7');
    WebRuntime::sample(always: true);

    // Another server behind the same load balancer, on the old nginx.
    serverFile('/etc/machine-id', str_repeat('b', 32)."\n");
    WebRuntime::forget();
    request()->server->set('SERVER_SOFTWARE', 'nginx/1.28.1');
    WebRuntime::sample(always: true);
    expect(WebRuntime::read()['server']['version'])->toBe('1.28.1');

    serverFile('/etc/machine-id', str_repeat('a', 32)."\n");
    expect(WebRuntime::read()['server']['version'])->toBe('1.30.7');
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
