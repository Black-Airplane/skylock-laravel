<?php

namespace BlackAirplane\SkyLockReporter\Tests;

use BlackAirplane\SkyLockReporter\LocalState;
use BlackAirplane\SkyLockReporter\SkyLockReporterServiceProvider;
use Orchestra\Testbench\TestCase as Orchestra;

abstract class TestCase extends Orchestra
{
    protected function getPackageProviders($app): array
    {
        return [SkyLockReporterServiceProvider::class];
    }

    protected function setUp(): void
    {
        parent::setUp();

        // What the reporter remembers between minutes, kept apart for each test.
        LocalState::$path = sys_get_temp_dir().'/skylock-reporter-'.bin2hex(random_bytes(6)).'.json';
    }

    protected function tearDown(): void
    {
        @unlink((string) LocalState::$path);
        LocalState::$path = null;

        parent::tearDown();
    }
}
