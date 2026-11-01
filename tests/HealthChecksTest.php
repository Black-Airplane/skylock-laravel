<?php

use BlackAirplane\SkyLockReporter\HealthChecks;
use Illuminate\Database\QueryException;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Schema;

function names(array $results): array
{
    return array_column($results, 'name');
}

it('checks the database, cache and disk, and skips what the app doesn’t use', function () {
    config(['queue.default' => 'sync']);

    $results = app(HealthChecks::class)->run();

    expect(names($results))->toContain('Database', 'Cache')
        ->and(names($results))->not->toContain('Queue size', 'Failed jobs, last hour')
        ->and(collect($results)->firstWhere('name', 'Database')['status'])->toBe('pass');
});

it('watches a database queue and recent failed jobs', function () {
    Schema::create('jobs', function (Blueprint $table): void {
        $table->id();
        $table->string('queue');
        $table->longText('payload');
        $table->unsignedTinyInteger('attempts');
        $table->unsignedInteger('reserved_at')->nullable();
        $table->unsignedInteger('available_at');
        $table->unsignedInteger('created_at');
    });
    Schema::create('failed_jobs', function (Blueprint $table): void {
        $table->id();
        $table->string('uuid')->unique();
        $table->text('connection');
        $table->text('queue');
        $table->longText('payload');
        $table->longText('exception');
        $table->timestamp('failed_at')->useCurrent();
    });
    config(['queue.default' => 'database', 'queue.connections.database.table' => 'jobs', 'queue.failed.table' => 'failed_jobs']);
    DB::table('jobs')->insert(['queue' => 'default', 'payload' => '{}', 'attempts' => 0, 'available_at' => time() - 1200, 'created_at' => time() - 1200]);
    DB::table('failed_jobs')->insert(['uuid' => 'a', 'connection' => 'database', 'queue' => 'default', 'payload' => '{}', 'exception' => 'Secret exception text', 'failed_at' => now()]);

    $results = collect(app(HealthChecks::class)->run())->keyBy('name');

    expect($results['Queue size'])->toMatchArray(['status' => 'pass', 'value' => 1, 'unit' => 'jobs'])
        ->and($results['Oldest queued job']['status'])->toBe('warn')
        ->and($results['Oldest queued job']['value'])->toBeGreaterThanOrEqual(1200)
        ->and($results['Failed jobs, last hour'])->toMatchArray(['status' => 'warn', 'value' => 1]);
});

it('marks a broken check failed instead of throwing', function () {
    config(['database.connections.broken' => ['driver' => 'sqlite', 'database' => '/nonexistent/skylock.sqlite'], 'database.default' => 'broken', 'queue.default' => 'sync']);

    $results = collect(app(HealthChecks::class)->run())->keyBy('name');

    expect($results['Database']['status'])->toBe('fail');
});

it('sends only names, statuses and numbers', function () {
    foreach (app(HealthChecks::class)->run() as $result) {
        expect(array_diff(array_keys($result), ['name', 'status', 'value', 'unit']))->toBe([]);
    }
});

it('keeps check names to what SkyLock accepts', function () {
    expect(HealthChecks::name('Redis <primary> ✓'))->toBe('Redis  primary')
        ->and(HealthChecks::name('***'))->toBe('Check')
        ->and(mb_strlen(HealthChecks::name(str_repeat('a', 200))))->toBe(80);
});

it('asks the database two questions: can it answer, and how many jobs failed', function (bool $hasTable) {
    config(['queue.default' => 'sync', 'queue.failed.table' => 'failed_jobs']);
    if ($hasTable) {
        Schema::create('failed_jobs', function (Blueprint $table): void {
            $table->id();
            $table->timestamp('failed_at')->useCurrent();
        });
    }
    $asked = 0;
    DB::beforeExecuting(function () use (&$asked): void {
        $asked++;
    });

    $results = collect(app(HealthChecks::class)->run())->keyBy('name');

    expect($asked)->toBe(2)
        ->and($results->has('Failed jobs, last hour'))->toBe($hasTable)
        ->and($results['Database']['status'])->toBe('pass');
})->with(['with a failed_jobs table' => true, 'without one' => false]);

it('marks the failed jobs check failed when the count fails for another reason', function () {
    config(['queue.default' => 'sync', 'queue.failed.table' => 'failed_jobs']);
    DB::beforeExecuting(function (string $query): void {
        if (str_contains($query, 'failed_jobs')) {
            $lost = new PDOException('server closed the connection unexpectedly');
            (fn () => $this->code = '08006')->call($lost);

            throw new QueryException('testing', $query, [], $lost);
        }
    });

    $results = collect(app(HealthChecks::class)->run())->keyBy('name');

    expect($results['Failed jobs, last hour']['status'])->toBe('fail');
});

it('checks the cache with a write and a read, and nothing left to delete', function () {
    config(['queue.default' => 'sync']);
    $calls = [];
    Event::listen('Illuminate\Cache\Events\*', function (string $name) use (&$calls): void {
        $calls[] = class_basename($name);
    });

    $first = collect(app(HealthChecks::class)->run())->firstWhere('name', 'Cache');
    config(['cache.default' => 'null']);
    $broken = collect(app(HealthChecks::class)->run())->firstWhere('name', 'Cache');

    expect($first['status'])->toBe('pass')
        ->and($broken['status'])->toBe('fail')
        ->and($calls)->not->toContain('ForgettingKey', 'KeyForgotten');
});
