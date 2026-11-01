<?php

namespace BlackAirplane\SkyLockReporter;

use Throwable;

/**
 * What the reporter remembers between minutes: the schedule's hash and what
 * the app runs on. One small file under storage, not the app's cache, so
 * remembering costs the app nothing it would see: no cache calls, no queries
 * when the cache is the database, nothing in Nightwatch. It's per server,
 * which suits what it holds, since each server reads its own stack. When it
 * can't be read or written, the reporter reads or sends again instead.
 */
final class LocalState
{
    /** Where it's kept; tests point it somewhere else. */
    public static ?string $path = null;

    public static function get(string $key): mixed
    {
        return self::all()[$key] ?? null;
    }

    public static function put(string $key, mixed $value): void
    {
        try {
            $path = self::path();
            $directory = dirname($path);
            if (! is_dir($directory)) {
                @mkdir($directory, 0755, true);
            }

            // Written beside it and moved into place, so a reader never sees half a file.
            $temporary = $path.'.'.bin2hex(random_bytes(4)).'.tmp';
            $json = json_encode([...self::all(), $key => $value], JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
            if (@file_put_contents($temporary, $json) === false || ! @rename($temporary, $path)) {
                @unlink($temporary);
            }
        } catch (Throwable) {
            // Not remembered: the next minute reads or sends it again.
        }
    }

    public static function path(): string
    {
        return self::$path ?? storage_path('framework/cache/skylock-reporter.json');
    }

    /**
     * @return array<string, mixed>
     */
    private static function all(): array
    {
        $json = @file_get_contents(self::path());
        if ($json === false) {
            return [];
        }

        $state = json_decode($json, true);

        return is_array($state) ? $state : [];
    }
}
