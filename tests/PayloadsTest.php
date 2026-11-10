<?php

use BlackAirplane\SkyLockReporter\Stack;
use BlackAirplane\SkyLockReporter\WebRuntime;
use Illuminate\Console\Events\ScheduledTaskFailed;
use Illuminate\Console\Events\ScheduledTaskFinished;
use Illuminate\Console\Events\ScheduledTaskSkipped;
use Illuminate\Console\Events\ScheduledTaskStarting;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;

/*
 * Everything the reporter sends, field by field. This is the list in the
 * README's "What it sends", as a test: a field that isn't here fails it, so
 * sending something new means adding it here, and to the README and
 * SkyLock's SECURITY.md, on purpose.
 */

const SENT = [
    'POST health' => [
        'phpVersion', 'laravelVersion', 'packageVersion', 'debug', 'production', 'errorMonitoring',
        'checks.*.name', 'checks.*.status', 'checks.*.value', 'checks.*.unit',
        'stackHash', 'stackCollectedAt',
    ],
    'PUT stack' => [
        'schema',
        'os', 'os.type', 'os.id', 'os.version', 'os.container',
        'patching', 'patching.securityUpdates', 'patching.restartPending', 'patching.restartPendingSince', 'patching.restartForKernel',
        'patching.kernel', 'patching.kernel.version', 'patching.kernel.flavour', 'patching.autoUpdates', 'patching.extendedSupport',
        'php.cli', 'php.web', 'php.webSapi',
        'laravel',
        'database', 'database.product', 'database.version',
        'cache', 'cache.product', 'cache.version',
        'deployedCommit', 'hosting', 'serverKey', 'errorMonitoring', 'collectedAt',
    ],
    'PUT schedule' => ['tasks.*.key', 'tasks.*.label', 'tasks.*.expression', 'tasks.*.timezone'],
    'POST task-events' => ['key', 'event', 'reason', 'durationMs'],
];

/**
 * Every field in a payload, as a path: `checks.*.name`. An empty or missing
 * part is its own path (`patching`), so "nothing to say" is allowed for too.
 *
 * @param  array<mixed>  $data
 * @return list<string>
 */
function fieldsIn(array $data, string $prefix = ''): array
{
    $fields = [];
    foreach ($data as $key => $value) {
        $path = $prefix.(is_int($key) ? '*' : $key);
        $fields = is_array($value) && $value !== [] ? [...$fields, ...fieldsIn($value, $path.'.')] : [...$fields, $path];
    }

    return array_values(array_unique($fields));
}

beforeEach(function () {
    $this->root = sys_get_temp_dir().'/skylock-payloads-'.bin2hex(random_bytes(4));
    mkdir($this->root, recursive: true);
    Stack::$root = $this->root;
    WebRuntime::forget();
});

afterEach(function () {
    Stack::$root = '';
    exec('rm -rf '.escapeshellarg($this->root));
});

it('sends these fields to these four places, and nothing else', function () {
    reporterConfigured();
    ubuntuServer();
    config(['cache.default' => 'file', 'sentry.dsn' => 'https://sentinel-dsn@o1.ingest.sentry.io/1', 'app.key' => 'base64:sentinel-app-key']);
    WebRuntime::sample(always: true);
    $schedule = app(Schedule::class);
    $schedule->command('backup:run --password=sentinel-argument')->daily();
    $schedule->exec('API_KEY=sentinel-variable /home/forge/sentinel-path/bin/sync.sh')->hourly();
    Http::fake([
        'skylock.example/api/ingest/health' => Http::response(['accepted' => true, 'scheduleKnown' => false, 'stackKnown' => false], 202),
        '*' => Http::response(['accepted' => true, 'known' => true], 202),
    ]);

    $this->artisan('skylock:report')->assertSuccessful();
    $task = collect($schedule->events())->first(fn ($event): bool => str_contains((string) $event->command, 'backup:run'));
    event(new ScheduledTaskStarting($task));
    event(new ScheduledTaskFinished($task, 1.5));
    event(new ScheduledTaskFailed($task, new RuntimeException('connection to sentinel-host.internal refused')));
    event(new ScheduledTaskSkipped($task));

    $sent = Http::recorded()->map(fn (array $pair): Request => $pair[0]);
    $to = fn (Request $request): string => $request->method().' '.str_replace('https://skylock.example/api/ingest/', '', $request->url());

    expect($sent->map($to)->unique()->sort()->values()->all())->toBe(collect(array_keys(SENT))->sort()->values()->all());

    foreach ($sent as $request) {
        expect(array_diff(fieldsIn($request->data()), SENT[$to($request)]))->toBe([], $to($request))
            ->and($request->body())->not->toContain('sentinel')
            // Nothing rides along beside the body either.
            ->and(array_diff(array_map(strtolower(...), array_keys($request->headers())), ['authorization', 'accept', 'content-type', 'content-length', 'user-agent', 'host']))->toBe([])
            ->and($request->header('User-Agent')[0] ?? '')->toStartWith('GuzzleHttp/')
            ->and(parse_url($request->url(), PHP_URL_QUERY))->toBeNull();
    }

    // The top of each payload is exactly this, whatever the server it ran on.
    $first = fn (string $place): array => $sent->first(fn (Request $request): bool => $to($request) === $place)->data();
    expect(array_keys($first('POST health')))->toBe(['phpVersion', 'laravelVersion', 'packageVersion', 'debug', 'production', 'errorMonitoring', 'checks', 'stackHash', 'stackCollectedAt'])
        ->and(array_keys($first('PUT stack')))->toBe(['schema', 'os', 'patching', 'php', 'laravel', 'database', 'cache', 'deployedCommit', 'hosting', 'serverKey', 'errorMonitoring', 'collectedAt'])
        ->and(array_keys($first('PUT schedule')))->toBe(['tasks'])
        ->and(collect($first('PUT schedule')['tasks'])->pluck('label')->all())->toBe(['backup:run', 'sync.sh'])
        ->and($sent->filter(fn (Request $request): bool => $to($request) === 'POST task-events')->map(fn (Request $request): array => array_keys($request->data()))->values()->all())->toBe([
            ['key', 'event'],
            ['key', 'event', 'durationMs'],
            ['key', 'event'],
            ['key', 'event', 'reason'],
        ]);
});

it('sends these fields about an Ubuntu server’s patching, and nothing else', function () {
    // Called directly, so it's checked on any system: telling which one this is reads /etc, which is Linux only.
    ubuntuServer();

    expect(array_diff(fieldsIn(['patching' => ubuntuPatching()]), SENT['PUT stack']))->toBe([])
        ->and(array_keys((array) ubuntuPatching()))->toBe(['securityUpdates', 'restartPending', 'restartPendingSince', 'restartForKernel', 'kernel', 'autoUpdates', 'extendedSupport']);
});

it('sends the web PHP only as a version and a SAPI name, whatever the cache holds', function (mixed $kept, ?array $read) {
    config(['cache.default' => 'file']);
    cache()->put(WebRuntime::CACHE_KEY, $kept, 60);

    expect(WebRuntime::read())->toBe($read);
})->with([
    'what it wrote' => [['version' => '8.4.12', 'sapi' => 'fpm-fcgi'], ['version' => '8.4.12', 'sapi' => 'fpm-fcgi']],
    'a distribution’s version' => [['version' => '8.3.6-1ubuntu0.1', 'sapi' => 'apache2handler'], ['version' => '8.3.6-1ubuntu0.1', 'sapi' => 'apache2handler']],
    'a host where the version should be' => [['version' => 'db-7.internal/secret', 'sapi' => 'fpm-fcgi'], null],
    'words where the SAPI should be' => [['version' => '8.4.12', 'sapi' => 'connection refused'], null],
    'something else entirely' => ['8.4.12', null],
]);
