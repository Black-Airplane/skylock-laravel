<?php

namespace BlackAirplane\SkyLockReporter;

use Illuminate\Console\Scheduling\Event;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Support\Str;

/**
 * How the app's scheduled tasks are described to SkyLock. A task's key is
 * a hash of its full command, so arguments and options never leave the app;
 * its label is the task's description, or just the command's name.
 */
final class ScheduledTasks
{
    /**
     * @return list<array{key: string, label: string, expression: string, timezone: string}>
     */
    public static function all(Schedule $schedule): array
    {
        $tasks = [];
        foreach ($schedule->events() as $event) {
            if (self::isOwn($event)) {
                continue;
            }
            $tasks[self::key($event)] = [
                'key' => self::key($event),
                'label' => self::label($event),
                'expression' => $event->expression,
                'timezone' => self::timezone($event),
            ];
        }

        return array_values($tasks);
    }

    public static function key(Event $event): string
    {
        return sha1(self::command($event).'|'.$event->expression.'|'.self::timezone($event));
    }

    public static function label(Event $event): string
    {
        $label = $event->description !== null && $event->description !== '' ? $event->description : (Str::before(self::command($event), ' ') ?: 'Scheduled task');

        return mb_substr(trim((string) preg_replace('/\p{C}+/u', ' ', $label)), 0, 120);
    }

    /**
     * The report itself shouldn't be reported on.
     */
    public static function isOwn(Event $event): bool
    {
        return str_contains(self::command($event), 'skylock:report');
    }

    /**
     * The command without the PHP binary and artisan path, which vary by server.
     */
    private static function command(Event $event): string
    {
        $command = (string) ($event->command ?? $event->description ?? 'closure');
        $command = str_contains($command, 'artisan') ? Str::after($command, 'artisan') : $command;

        return trim(str_replace(["'", '"'], '', $command));
    }

    private static function timezone(Event $event): string
    {
        $timezone = $event->timezone ?? config('app.timezone', 'UTC');

        return $timezone instanceof \DateTimeZone ? $timezone->getName() : (string) $timezone;
    }
}
