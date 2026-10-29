<?php

namespace BlackAirplane\SkyLockReporter;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Talks to SkyLock. It never throws and never waits long: a monitoring
 * outage must not become your app's outage. Failures are logged at most
 * once every ten minutes.
 */
final class SkyLockClient
{
    public function configured(): bool
    {
        return is_string(config('skylock-reporter.token')) && config('skylock-reporter.token') !== '';
    }

    /**
     * @param  array<string, mixed>  $payload
     * @return array<string, mixed>|null the response, or null when it didn't get through
     */
    public function send(string $method, string $path, array $payload): ?array
    {
        if (! $this->configured()) {
            return null;
        }

        try {
            $response = Http::withToken((string) config('skylock-reporter.token'))
                ->acceptJson()
                ->timeout((int) config('skylock-reporter.timeout', 3))
                ->connectTimeout(2)
                ->send($method, rtrim((string) config('skylock-reporter.url'), '/').'/api/ingest/'.$path, ['json' => $payload]);

            if ($response->successful()) {
                return (array) $response->json();
            }

            $this->warn("SkyLock answered {$response->status()} to {$path}.");
        } catch (Throwable $exception) {
            $this->warn('Could not reach SkyLock: '.$exception::class);
        }

        return null;
    }

    private function warn(string $message): void
    {
        try {
            if (Cache::add('skylock-reporter:warned', true, 600)) {
                Log::warning("[SkyLock reporter] {$message}");
            }
        } catch (Throwable) {
            // The cache is down too; stay quiet rather than fail.
        }
    }
}
