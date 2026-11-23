<?php

namespace BlackAirplane\SkyLockReporter;

use Illuminate\Support\Facades\Date;
use Throwable;

/**
 * What the reporter remembers between minutes: the schedule's hash, what the
 * app runs on, and the task events the scheduler noted for the minute report.
 * Small files under storage, not the app's cache, so remembering costs the
 * app nothing it would see: no cache calls, no queries when the cache is the
 * database, nothing in Nightwatch. They're per server, which suits what they
 * hold, since each server reads its own stack and runs its own scheduler.
 * When they can't be read or written, the reporter reads or sends again
 * instead.
 *
 * Only the user the app runs as can read them (mode 0600): they say what the
 * server runs on and what it does, which is nobody else's to know on a
 * shared machine.
 */
final class LocalState
{
    /** Where it's kept; tests point it somewhere else. */
    public static ?string $path = null;

    /**
     * How much may wait to be sent, about a thousand events: hours of a busy
     * schedule's while SkyLock can't be reached. Past it, events are sent
     * straight away again, as before 0.6.
     */
    private const NOTED_MAX_BYTES = 131072;

    /** An event that has waited longer than this isn't sent: SkyLock has long heard of later runs. */
    public const NOTED_KEEP_SECONDS = 172800;

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
            // Closed before anything is written to it, and the move keeps the mode.
            @touch($temporary);
            @chmod($temporary, 0600);
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
     * Notes a task event for the minute report to send: a line added to a
     * file beside this one under an exclusive lock, so the scheduler, a
     * background task finishing and the report never write over each other.
     *
     * @param  array<string, mixed>  $event
     * @return bool Whether it was noted. When it wasn't (storage can't be
     *              written, or the report hasn't sent anything for hours),
     *              the caller sends it straight away instead.
     */
    public static function note(array $event): bool
    {
        try {
            $path = self::notedPath();
            if (is_file($path) && (int) filesize($path) > self::NOTED_MAX_BYTES) {
                return false;
            }
            if (! is_file($path)) {
                $directory = dirname($path);
                if (! is_dir($directory)) {
                    @mkdir($directory, 0755, true);
                }
                @touch($path);
                @chmod($path, 0600);
            }

            $line = json_encode($event, JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR)."\n";

            return @file_put_contents($path, $line, FILE_APPEND | LOCK_EX) === strlen($line);
        } catch (Throwable) {
            return false;
        }
    }

    /**
     * Everything noted and not yet sent, oldest first: what an earlier report
     * kept for this one, then what's been noted since. The noted file is moved
     * aside before it's read, so the scheduler notes what comes next in a new
     * one; a locked write of nothing to the moved file first waits out a line
     * still being added to it.
     *
     * @return list<array<string, mixed>>
     */
    public static function takeNoted(): array
    {
        $kept = self::lines(self::keptPath());

        $path = self::notedPath();
        $noted = [];
        if (is_file($path)) {
            $taken = $path.'.'.bin2hex(random_bytes(4)).'.taken';
            if (@rename($path, $taken)) {
                @file_put_contents($taken, '', FILE_APPEND | LOCK_EX);
                $noted = self::lines($taken);
                @unlink($taken);
            }
        }

        $oldest = Date::now()->getTimestamp() - self::NOTED_KEEP_SECONDS;

        return array_values(array_filter([...$kept, ...$noted], fn (array $event): bool => is_int($event['at'] ?? null) && $event['at'] >= $oldest));
    }

    /**
     * Keeps what a report took and didn't send, for the next one to send
     * ahead of anything noted since. Only the report writes this file, and
     * one report runs at a time, so nothing else writes it meanwhile.
     *
     * @param  list<array<string, mixed>>  $events
     */
    public static function keepNoted(array $events): void
    {
        $path = self::keptPath();
        if ($events === []) {
            @unlink($path);

            return;
        }

        try {
            $temporary = $path.'.'.bin2hex(random_bytes(4)).'.tmp';
            $lines = implode('', array_map(fn (array $event): string => json_encode($event, JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR)."\n", $events));
            @touch($temporary);
            @chmod($temporary, 0600);
            if (@file_put_contents($temporary, $lines) === false || ! @rename($temporary, $path)) {
                @unlink($temporary);
            }
        } catch (Throwable) {
            // Not kept: SkyLock hears of these runs from the next ones.
        }
    }

    public static function notedPath(): string
    {
        return self::path().'.noted';
    }

    public static function keptPath(): string
    {
        return self::path().'.kept';
    }

    /**
     * @return list<array<string, mixed>>
     */
    private static function lines(string $path): array
    {
        $contents = @file_get_contents($path);
        if ($contents === false) {
            return [];
        }

        $events = [];
        foreach (explode("\n", $contents) as $line) {
            $event = $line === '' ? null : json_decode($line, true);
            if (is_array($event)) {
                $events[] = $event;
            }
        }

        return $events;
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
