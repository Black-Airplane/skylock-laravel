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
            $this->components->error('Set SKYLOCK_REPORTER_TOKEN in .env first. Create the token on the application\'s Status tab in SkyLock.');

            return self::FAILURE;
        }

        $results = $checks->run();
        $this->table(['Check', 'Status', 'Value'], array_map(fn (array $result): array => [
            $result['name'],
            $result['status'],
            isset($result['value']) ? $result['value'].' '.($result['unit'] ?? '') : '',
        ], $results));

        $sent = $client->send('POST', 'health', ReportCommand::payload($results));

        if ($sent === null) {
            $this->components->error('SkyLock didn\'t accept the report. Check SKYLOCK_REPORTER_URL and SKYLOCK_REPORTER_TOKEN, and the log.');

            return self::FAILURE;
        }

        $this->components->info('Connected. SkyLock received this report; it will arrive every minute from the scheduler.');

        return self::SUCCESS;
    }
}
