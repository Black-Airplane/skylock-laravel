<?php

namespace BlackAirplane\SkyLockReporter\Commands;

use BlackAirplane\SkyLockReporter\HealthChecks;
use BlackAirplane\SkyLockReporter\SkyLockClient;
use Illuminate\Console\Command;

class TestCommand extends Command
{
    protected $signature = 'skylock:test';

    protected $description = 'Check the SkyLock reporter is set up, and show exactly what it sends';

    public function handle(SkyLockClient $client, HealthChecks $checks): int
    {
        if (! $client->configured()) {
            $this->components->error('Set SKYLOCK_REPORTER_URL and SKYLOCK_REPORTER_TOKEN in .env first. An Owner or Admin creates the token in SkyLock, on the application\'s Settings tab under "From inside the app".');

            return self::FAILURE;
        }

        if (! $client->secure()) {
            $this->components->error('SKYLOCK_REPORTER_URL must start with https://, so the token is never sent unencrypted. Nothing was sent.');

            return self::FAILURE;
        }

        $results = $checks->run();
        $this->table(['Check', 'Status', 'Value'], array_map(fn (array $result): array => [
            $result['name'],
            $result['status'],
            isset($result['value']) ? $result['value'].' '.($result['unit'] ?? '') : '',
        ], $results));

        $read = ReportCommand::stack(fresh: true);
        $stack = $read['stack'];
        $this->table(['What it runs on', 'Sent as'], array_map(
            fn (string $key, mixed $value): array => [$key, is_scalar($value) || $value === null ? var_export($value, true) : (string) json_encode($value)],
            array_keys($stack),
            $stack,
        ));

        // Sent even when SkyLock revoked the token: this is how to find out it's been restored.
        $sent = $client->send('POST', 'health', [...ReportCommand::payload($results), 'stackHash' => ReportCommand::hash($stack), 'stackCollectedAt' => $read['collectedAt']], force: true);
        if ($sent !== null && array_key_exists('stackKnown', $sent)) {
            $client->send('PUT', 'stack', ReportCommand::stackPayload($read));
        }

        if ($sent === null && $client->revoked()) {
            $this->components->error('SkyLock no longer accepts this token: its application was retired or its client was offboarded. Remove the package, or ask an Owner or Admin for a new token.');

            return self::FAILURE;
        }

        if ($sent === null) {
            $this->components->error('SkyLock didn\'t accept the report. Check SKYLOCK_REPORTER_URL and SKYLOCK_REPORTER_TOKEN, and the log.');

            return self::FAILURE;
        }

        $this->components->info('Connected. SkyLock received this report; it will arrive every minute from the scheduler.');

        return self::SUCCESS;
    }
}
