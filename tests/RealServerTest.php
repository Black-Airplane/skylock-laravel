<?php

use BlackAirplane\SkyLockReporter\DebianVersion;
use BlackAirplane\SkyLockReporter\Stack;

/*
 * On a real Ubuntu server, held against apt itself: the security updates the
 * reporter counts from apt's lists, and the order it puts versions in.
 * Fixtures only show the cases someone thought of; these show the archive.
 * CI runs them on Ubuntu 22.04 and 24.04 (SKYLOCK_REAL_SERVER=1, with
 * python3-apt installed and apt's lists fresh).
 */

beforeEach(function () {
    if (getenv('SKYLOCK_REAL_SERVER') !== '1') {
        $this->markTestSkipped('Runs on an Ubuntu server with python3-apt: SKYLOCK_REAL_SERVER=1.');
    }
});

afterEach(function () {
    Stack::$root = '';
});

/**
 * @return array{count: int, pairs: list<array{string, string, int}>, madeOlder: int}
 */
function aptSays(string ...$arguments): array
{
    $script = __DIR__.'/RealServer/apt_security_updates.py';
    exec('/usr/bin/python3 '.implode(' ', array_map('escapeshellarg', [$script, ...$arguments])).' 2>&1', $output, $code);
    expect($code)->toBe(0, implode("\n", $output));

    return json_decode((string) end($output), true, flags: JSON_THROW_ON_ERROR);
}

function reporterCounts(string $root = ''): ?int
{
    Stack::$root = $root;

    return Closure::bind(fn (): ?int => Stack::securityUpdates(), null, Stack::class)();
}

it('puts versions in the order apt does, for every package with more than one', function () {
    $pairs = aptSays()['pairs'];
    $wrong = array_values(array_filter($pairs, fn (array $pair): bool => DebianVersion::compare($pair[0], $pair[1]) !== ($pair[2] <=> 0)));

    expect(count($pairs))->toBeGreaterThan(1000)
        ->and(array_slice($wrong, 0, 10))->toBe([]);
});

it('counts the security updates apt would say this server is missing', function () {
    expect(reporterCounts())->toBe(aptSays()['count']);
});

it('counts the same as apt once some packages are behind', function () {
    $root = sys_get_temp_dir().'/skylock-real-server-'.bin2hex(random_bytes(4));
    mkdir("{$root}/etc", recursive: true);
    mkdir("{$root}/var/lib/dpkg", recursive: true);
    mkdir("{$root}/var/lib/apt");
    copy('/etc/os-release', "{$root}/etc/os-release");
    symlink('/var/lib/apt/lists', "{$root}/var/lib/apt/lists");

    try {
        $made = aptSays('--write-older', "{$root}/var/lib/dpkg/status")['madeOlder'];
        $apt = aptSays('--status', "{$root}/var/lib/dpkg/status")['count'];

        expect($made)->toBeGreaterThan(5)
            ->and($apt)->toBeGreaterThanOrEqual($made)
            ->and(reporterCounts($root))->toBe($apt);
    } finally {
        exec('rm -rf '.escapeshellarg($root));
    }
});

it('reads every list in seconds, a few megabytes at a time', function () {
    $memory = memory_get_usage();
    memory_reset_peak_usage();
    $started = hrtime(true);

    reporterCounts();

    $seconds = (hrtime(true) - $started) / 1e9;
    $megabytes = (memory_get_peak_usage() - $memory) / 1024 / 1024;
    fwrite(STDERR, sprintf("\nCounted in %.2f s, peak %.1f MB more.\n", $seconds, $megabytes));

    expect($seconds)->toBeLessThan(5)
        ->and($megabytes)->toBeLessThan(48);
});
