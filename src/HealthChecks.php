<?php

namespace BlackAirplane\SkyLockReporter;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Schema;
use Throwable;

/**
 * The app's health, as names, statuses and numbers. Nothing else leaves
 * the app: no messages, hosts, queries or exception text. If
 * spatie/laravel-health is installed, its latest results are reported too.
 */
final class HealthChecks
{
    /**
     * @return list<array{name: string, status: string, value?: float|int, unit?: string}>
     */
    public function run(): array
    {
        $results = [
            $this->attempt('Database', function (): array {
                DB::connection()->select('select 1');

                return ['status' => 'pass'];
            }),
            $this->attempt('Cache', function (): array {
                $key = 'skylock-reporter:probe';
                Cache::put($key, 'ok', 30);
                $ok = Cache::get($key) === 'ok';
                Cache::forget($key);

                return ['status' => $ok ? 'pass' : 'fail'];
            }),
            ...$this->queue(),
            ...$this->failedJobs(),
            $this->disk(),
        ];

        return [...array_values(array_filter($results)), ...$this->laravelHealth()];
    }

    /**
     * @return list<array{name: string, status: string, value?: float|int, unit?: string}>
     */
    private function queue(): array
    {
        if (config('queue.default') === 'sync') {
            return [];
        }

        $results = [$this->attempt('Queue size', function (): array {
            $size = Queue::size();

            return ['status' => $size > (int) config('skylock-reporter.thresholds.queue_size') ? 'warn' : 'pass', 'value' => $size, 'unit' => 'jobs'];
        })];

        if (config('queue.connections.'.config('queue.default').'.driver') === 'database') {
            $results[] = $this->attempt('Oldest queued job', function (): array {
                $table = (string) config('queue.connections.'.config('queue.default').'.table', 'jobs');
                $oldest = DB::table($table)->whereNull('reserved_at')->min('available_at');
                $age = $oldest === null ? 0 : max(0, time() - (int) $oldest);

                return ['status' => $age > (int) config('skylock-reporter.thresholds.oldest_job_seconds') ? 'warn' : 'pass', 'value' => $age, 'unit' => 's'];
            });
        }

        return array_values(array_filter($results));
    }

    /**
     * @return list<array{name: string, status: string, value?: float|int, unit?: string}>
     */
    private function failedJobs(): array
    {
        $table = (string) config('queue.failed.table', 'failed_jobs');

        try {
            if (! Schema::hasTable($table)) {
                return [];
            }
        } catch (Throwable) {
            return [];
        }

        $result = $this->attempt('Failed jobs, last hour', function () use ($table): array {
            $count = DB::table($table)->where('failed_at', '>=', now()->subHour())->count();

            return ['status' => $count > 0 ? 'warn' : 'pass', 'value' => $count, 'unit' => 'jobs'];
        });

        return $result === null ? [] : [$result];
    }

    /**
     * @return array{name: string, status: string, value?: float|int, unit?: string}|null
     */
    private function disk(): ?array
    {
        return $this->attempt('Disk used', function (): array {
            $free = @disk_free_space(storage_path());
            $total = @disk_total_space(storage_path());
            if (! is_float($free) || ! is_float($total) || $total <= 0) {
                return ['status' => 'skip'];
            }
            $used = round((1 - $free / $total) * 100, 1);

            return [
                'status' => $used >= (float) config('skylock-reporter.thresholds.disk_used_fail') ? 'fail' : ($used >= (float) config('skylock-reporter.thresholds.disk_used_warn') ? 'warn' : 'pass'),
                'value' => $used,
                'unit' => '%',
            ];
        });
    }

    /**
     * @return list<array{name: string, status: string}>
     */
    private function laravelHealth(): array
    {
        $store = 'Spatie\\Health\\ResultStores\\ResultStore';
        if (! interface_exists($store) || ! app()->bound($store)) {
            return [];
        }

        try {
            $latest = app($store)->latestResults();
            if ($latest === null) {
                return [];
            }

            $results = [];
            foreach ($latest->storedCheckResults as $result) {
                $results[] = ['name' => self::name((string) ($result->label ?: $result->name)), 'status' => (string) $result->status];
            }

            return $results;
        } catch (Throwable) {
            return [];
        }
    }

    /**
     * @param  callable(): array{status: string, value?: float|int, unit?: string}  $check
     * @return array{name: string, status: string, value?: float|int, unit?: string}|null
     */
    private function attempt(string $name, callable $check): ?array
    {
        try {
            $result = $check();
        } catch (Throwable) {
            $result = ['status' => 'fail'];
        }

        return $result['status'] === 'skip' ? null : ['name' => $name, ...$result];
    }

    /**
     * SkyLock accepts letters, numbers, spaces and . _ : , ( ) / - in names.
     */
    public static function name(string $name): string
    {
        return mb_substr(trim((string) preg_replace('/[^A-Za-z0-9 ._:,()\/\-]+/', ' ', $name)), 0, 80) ?: 'Check';
    }
}
