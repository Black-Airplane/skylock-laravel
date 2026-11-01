<?php

namespace BlackAirplane\SkyLockReporter;

use Illuminate\Console\Scheduling\Event;
use Laravel\Nightwatch\Console\Sample;
use Laravel\Nightwatch\Core;
use Laravel\Nightwatch\Facades\Nightwatch;
use Throwable;

/**
 * Keeps the reporter out of the way in Laravel Nightwatch, when the app uses
 * it. The minute report repeats 1,440 times a day and each run looks like the
 * last, yet Nightwatch would record every one: the scheduled task, its own
 * process, the request to SkyLock, its cache calls and queries. So the report
 * is recorded one run in a hundred (configurable), and a run Nightwatch skips
 * drops all of those at once. Nightwatch still records a skipped run that
 * throws, in full. Task events and the web PHP note are sent inside the
 * app's own tasks and requests, so Nightwatch is told to look away while
 * they happen. Without Nightwatch, none of this does anything.
 */
final class NightwatchSampling
{
    public const DEFAULT_RATE = 0.01;

    /**
     * The minute report as schedule:run records it, set when it's scheduled.
     * Nightwatch reads it when the task starts, or when it's skipped because
     * the last run is still going.
     */
    public static function task(Event $report): void
    {
        if (self::available() && class_exists(Sample::class)) {
            self::attempt(fn () => Sample::rate(self::rate())($report));
        }
    }

    /**
     * The report's own process (it runs in the background). Called after
     * Nightwatch has sampled the process at its command rate, so this decides.
     */
    public static function command(): void
    {
        if (self::available()) {
            self::attempt(fn () => Nightwatch::sample(self::rate()));
        }
    }

    /**
     * Runs the callback without Nightwatch recording what it does.
     *
     * @template T
     *
     * @param  callable(): T  $callback
     * @return T
     */
    public static function ignore(callable $callback): mixed
    {
        // Older Nightwatch releases than this package is built against have no ignore().
        // @phpstan-ignore function.alreadyNarrowedType
        if (! self::available() || ! method_exists(Core::class, 'ignore')) {
            return $callback();
        }

        return Nightwatch::ignore($callback);
    }

    public static function rate(): float
    {
        $rate = config('skylock-reporter.nightwatch_sample_rate', self::DEFAULT_RATE);

        return is_numeric($rate) ? max(0.0, min(1.0, (float) $rate)) : self::DEFAULT_RATE;
    }

    private static function available(): bool
    {
        return class_exists(Core::class) && app()->bound(Core::class);
    }

    private static function attempt(callable $callback): void
    {
        try {
            $callback();
        } catch (Throwable) {
            // Nightwatch is the app's to configure; the reporter never breaks it.
        }
    }
}
