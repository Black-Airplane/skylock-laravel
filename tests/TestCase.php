<?php

namespace BlackAirplane\SkyLockReporter\Tests;

use BlackAirplane\SkyLockReporter\SkyLockReporterServiceProvider;
use Orchestra\Testbench\TestCase as Orchestra;

abstract class TestCase extends Orchestra
{
    protected function getPackageProviders($app): array
    {
        return [SkyLockReporterServiceProvider::class];
    }
}
