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

/**
 * A file on the pretend server.
 */
function serverFile(string $path, string $contents = ''): void
{
    $full = test()->root.$path;
    @mkdir(dirname($full), recursive: true);
    file_put_contents($full, $contents);
}

function ubuntuServer(): void
{
    serverFile('/etc/os-release', "NAME=\"Ubuntu\"\nVERSION_ID=\"22.04\"\nID=ubuntu\nPRETTY_NAME=\"Ubuntu 22.04.5 LTS\"\n");
    serverFile('/etc/machine-id', "0123456789abcdef0123456789abcdef\n");
    serverFile('/var/lib/update-notifier/updates-available', "\n12 updates can be applied immediately.\n4 of these updates are standard security updates.\nTo see these additional updates run: apt list --upgradable\n");
    serverFile('/var/run/reboot-required', "*** System restart required ***\n");
    serverFile('/etc/apt/apt.conf.d/20auto-upgrades', "APT::Periodic::Update-Package-Lists \"1\";\nAPT::Periodic::Unattended-Upgrade \"1\";\n");
}

it('sends what it runs on as names from fixed lists, versions, counts and booleans', function () {
    if (PHP_OS_FAMILY !== 'Linux') {
        $this->markTestSkipped('Reading /etc is Linux only.');
    }
    ubuntuServer();
    config(['sentry.dsn' => 'https://sentinel-dsn@o1.ingest.sentry.io/1', 'skylock-reporter.commit' => str_repeat('ab', 20)]);

    $stack = Stack::collect();

    expect(array_keys($stack))->toBe(['schema', 'os', 'patching', 'php', 'laravel', 'database', 'cache', 'deployedCommit', 'hosting', 'serverKey', 'errorMonitoring'])
        ->and($stack['os'])->toBe(['type' => 'linux', 'id' => 'ubuntu', 'version' => '22.04', 'container' => false])
        ->and($stack['patching'])->toBe(['securityUpdates' => 4, 'restartPending' => true, 'autoUpdates' => true, 'extendedSupport' => false])
        ->and($stack['php']['cli'])->toBe(PHP_VERSION)
        ->and($stack['database']['product'])->toBe('sqlite')
        ->and($stack['deployedCommit'])->toBe(str_repeat('ab', 20))
        ->and($stack['serverKey'])->toBe(hash_hmac('sha256', 'skylock-reporter', '0123456789abcdef0123456789abcdef'))
        ->and($stack['errorMonitoring'])->toBeTrue()
        ->and(json_encode($stack))->not->toContain('sentinel-dsn')
        ->and(json_encode($stack))->not->toContain('0123456789abcdef0123456789abcdef')
        ->and(json_encode($stack))->not->toContain('Ubuntu 22.04.5 LTS');
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
    expect(WebRuntime::read())->toBe(['version' => PHP_VERSION, 'sapi' => PHP_SAPI]);

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
