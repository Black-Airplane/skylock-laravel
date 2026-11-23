<?php

use BlackAirplane\SkyLockReporter\DebianVersion;

// Each checked with `dpkg --compare-versions` on Ubuntu 24.04. RealServerTest holds
// the rest of the archive against apt.
it('puts versions in dpkg’s order', function (string $a, string $b, int $order) {
    expect(DebianVersion::compare($a, $b))->toBe($order)
        ->and(DebianVersion::compare($b, $a))->toBe(-$order);
})->with([
    'the same' => ['1.0', '1.0', 0],
    'no epoch is epoch 0' => ['0:1.0', '1.0', 0],
    'the epoch first' => ['1:0.1', '2.0', 1],
    'numbers as numbers' => ['1.10', '1.9', 1],
    'leading zeros' => ['1.01', '1.1', 0],
    'numbers longer than an integer' => ['1.99999999999999999999', '1.100000000000000000000', -1],
    'no revision is revision 0' => ['1.0', '1.0-0', 0],
    'the revision' => ['1.0-1', '1.0-2', -1],
    'the last hyphen starts the revision' => ['1.2-3-4', '1.2-3-5', -1],
    'a tilde before the end' => ['1.0~rc1', '1.0', -1],
    'two tildes before a tilde and a letter' => ['1.0~~', '1.0~~a', -1],
    'a tilde and a letter before a tilde' => ['1.0~~a', '1.0~', -1],
    'the end before a letter' => ['1.0', '1.0a', -1],
    'letters before other characters' => ['1.0a', '1.0+', -1],
    'a plus before a dot' => ['1.0+1', '1.0.1', -1],
    'an Ubuntu security respin' => ['3.0.2-0ubuntu1.18', '3.0.2-0ubuntu1.19', -1],
    'a backport to an older release' => ['2.4.125-1ubuntu0.1~24.04.2', '2.4.125-1ubuntu0.1~24.04.1', 1],
    'an ESM build after the security build' => ['1.0-1ubuntu0.1+esm1', '1.0-1ubuntu0.1', 1],
    'Ondřej’s build before a security respin' => ['2.3.3-9ubuntu5+ubuntu22.04.1+deb.sury.org+1', '2.3.3-9ubuntu5.1', -1],
    'nginx.org’s build after Ubuntu’s' => ['1.28.1-1~jammy', '1.18.0-6ubuntu14.6', 1],
    'a newer kernel' => ['6.8.0-149.149', '6.8.0-151.151', -1],
    'an epoch beats any version' => ['1:1.3.dfsg-3.1ubuntu2.1', '9.9', 1],
]);
