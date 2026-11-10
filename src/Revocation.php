<?php

namespace BlackAirplane\SkyLockReporter;

use Illuminate\Support\Facades\Date;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * SkyLock no longer accepts this token: its application was retired, or its
 * client offboarded, and SkyLock answered 410 with `revoked: true`. Until a
 * check once a day finds it's been restored, the reporter sends nothing, so
 * an app that's left SkyLock doesn't keep calling it every minute.
 *
 * Remembered per server in LocalState with a fingerprint of the token it was
 * for, so setting a new token resumes at once. `skylock:test` always sends,
 * so it resumes at once too.
 */
final class Revocation
{
    private const KEY = 'revoked';

    public const CHECK_EVERY_SECONDS = 86400;

    /** SkyLock revoked the token that's set now. */
    public static function active(): bool
    {
        $marker = LocalState::get(self::KEY);

        return is_array($marker) && ($marker['token'] ?? null) === self::fingerprint();
    }

    /** Time for the daily check. */
    public static function due(): bool
    {
        $marker = LocalState::get(self::KEY);
        $after = is_array($marker) ? ($marker['checkAfter'] ?? null) : null;

        try {
            return ! is_string($after) || Date::now()->gte(Date::parse($after));
        } catch (Throwable) {
            return true;
        }
    }

    /** Revoked, with the daily check not due: nothing is sent. */
    public static function waiting(): bool
    {
        return self::active() && ! self::due();
    }

    public static function mark(): void
    {
        LocalState::put(self::KEY, [
            'token' => self::fingerprint(),
            'checkAfter' => Date::now()->addSeconds(self::CHECK_EVERY_SECONDS)->utc()->toIso8601String(),
        ]);

        try {
            Log::warning('[SkyLock reporter] SkyLock no longer accepts this token (its application was retired or its client offboarded), so the reporter has stopped. It checks once a day. Remove the package, or set a new token.');
        } catch (Throwable) {
            // Logging is down too; stay quiet rather than fail.
        }
    }

    public static function clear(): void
    {
        if (LocalState::get(self::KEY) !== null) {
            LocalState::put(self::KEY, null);
        }
    }

    private static function fingerprint(): string
    {
        return hash('sha256', (string) config('skylock-reporter.token'));
    }
}
