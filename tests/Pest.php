<?php

use BlackAirplane\SkyLockReporter\SkyLockReporterServiceProvider;
use BlackAirplane\SkyLockReporter\Stack;
use BlackAirplane\SkyLockReporter\Tests\TestCase;
use Illuminate\Console\Scheduling\CacheEventMutex;
use Illuminate\Console\Scheduling\Event;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;

uses(TestCase::class)->in(__DIR__);

const TOKEN = 'slr_tttttttttttttttttttttttttttttttttttttttt';

/**
 * Configure the reporter, then boot the provider again the way an app with
 * a token in .env would boot it.
 */
function reporterConfigured(): void
{
    config(['skylock-reporter.url' => 'https://skylock.example', 'skylock-reporter.token' => TOKEN]);
    (new SkyLockReporterServiceProvider(app()))->boot();
}

function scheduledEvent(string $command, string $expression = '0 2 * * *'): Event
{
    $event = new Event(app(CacheEventMutex::class), $command, 'UTC');
    $event->cron($expression);

    return $event;
}

/**
 * Fakes SkyLock and returns every task event sent to it, as "label:event",
 * or "label:skipped:reason".
 * Anything else, like the minute report's health, is answered but not listed.
 *
 * @param  array<string, string>  $labels  task keys to names, to keep assertions readable
 * @param  bool|ArrayObject<string, bool>  $reachable  an ArrayObject's `up` can change mid-test
 */
function skyLock(array $labels, bool|ArrayObject $reachable = true): ArrayObject
{
    $sent = new ArrayObject;
    Http::fake(function (Request $request) use ($sent, $labels, $reachable) {
        $up = is_bool($reachable) ? $reachable : $reachable['up'];
        if (! str_ends_with($request->url(), '/task-events')) {
            return $up ? Http::response(['accepted' => true, 'scheduleKnown' => true, 'stackKnown' => true], 202) : Http::failedConnection()($request);
        }

        $sent[] = ($labels[$request['key']] ?? '?').':'.$request['event'].(isset($request['reason']) ? ':'.$request['reason'] : '');

        return $up ? Http::response(['accepted' => true, 'known' => true], 202) : Http::failedConnection()($request);
    });

    return $sent;
}

/**
 * A file on the pretend server (the test sets `$this->root` and points Stack::$root at it).
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
    serverFile('/var/run/reboot-required.pkgs', "linux-image-5.15.0-126-generic\nlinux-base\n");
    serverFile('/proc/version_signature', "Ubuntu 5.15.0-125.135-generic 5.15.167\n");
    touch(test()->root.'/var/run/reboot-required', strtotime('2026-10-30 02:05:00 UTC'));
    serverFile('/etc/apt/apt.conf.d/20auto-upgrades', "APT::Periodic::Update-Package-Lists \"1\";\nAPT::Periodic::Unattended-Upgrade \"1\";\n");
}

/**
 * What it reads about patching on an Ubuntu server. Called directly, so it
 * runs anywhere: telling which system this is reads /etc, which is Linux only.
 *
 * @return array<string, mixed>|null
 */
function ubuntuPatching(): ?array
{
    return Closure::bind(fn (): ?array => Stack::patching('ubuntu'), null, Stack::class)();
}
