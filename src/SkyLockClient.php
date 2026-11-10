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
 *
 * It sends over https only, so the token never crosses a network in the
 * clear, and never follows a redirect: a report goes to the address it was
 * given or nowhere.
 *
 * Once SkyLock says the token is revoked (see Revocation), it sends nothing
 * but the minute report once a day, to find out whether it's been restored.
 */
final class SkyLockClient
{
    /** The last send didn't go through because SkyLock revoked the token. */
    private bool $revoked = false;

    public function configured(): bool
    {
        return is_string(config('skylock-reporter.token')) && config('skylock-reporter.token') !== '';
    }

    /**
     * Whether SKYLOCK_REPORTER_URL is one the token can be sent to: https.
     * Plain http is for a SkyLock on a developer's own machine, so only an
     * app in the local or testing environment may use it.
     */
    public function secure(): bool
    {
        $url = strtolower($this->url());

        return str_starts_with($url, 'https://')
            || (str_starts_with($url, 'http://') && app()->environment('local', 'testing'));
    }

    /**
     * @param  array<string, mixed>  $payload
     * @param  bool  $force  send even when the token was revoked (someone ran a command to check)
     * @return array<string, mixed>|null the response, or null when it didn't get through
     */
    public function send(string $method, string $path, array $payload, bool $force = false): ?array
    {
        $this->revoked = false;
        if (! $this->configured()) {
            return null;
        }

        if (! $this->secure()) {
            $this->warn('SKYLOCK_REPORTER_URL must start with https://, so nothing was sent.');

            return null;
        }

        $held = Revocation::active();
        if ($held && ! $force && ($path !== 'health' || ! Revocation::due())) {
            $this->revoked = true;

            return null;
        }

        try {
            $response = Http::withToken((string) config('skylock-reporter.token'))
                ->acceptJson()
                ->timeout((int) config('skylock-reporter.timeout', 3))
                ->connectTimeout(2)
                ->withoutRedirecting()
                ->send($method, $this->url().'/api/ingest/'.$path, ['json' => $payload]);

            if ($response->successful()) {
                if ($held) {
                    Revocation::clear();
                }

                return (array) $response->json();
            }

            // Only SkyLock's own answer: a 410 from anything in between doesn't say `revoked`.
            if ($response->status() === 410 && $response->json('revoked') === true) {
                Revocation::mark();
                $this->revoked = true;

                return null;
            }

            $this->warn("SkyLock answered {$response->status()} to {$path}.");
        } catch (Throwable $exception) {
            $this->warn('Could not reach SkyLock: '.$exception::class);
        }

        return null;
    }

    public function revoked(): bool
    {
        return $this->revoked;
    }

    private function url(): string
    {
        return rtrim(trim((string) config('skylock-reporter.url')), '/');
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
