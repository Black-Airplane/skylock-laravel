<?php

namespace BlackAirplane\SkyLockReporter;

use Illuminate\Console\Scheduling\Event;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Contracts\Cache\Repository;
use Illuminate\Support\Str;
use Throwable;

/**
 * How the app's scheduled tasks are described to SkyLock. A task's key is
 * a hash of its full command, so arguments and options never leave the app;
 * its label is the task's description, or just the name of what it runs.
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
            // A task for other environments (`->environments('production')`) never runs here.
            if (self::isOwn($event) || ! $event->runsInEnvironment((string) app()->environment())) {
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

    /**
     * The task's description, in the words whoever wrote the schedule chose.
     * Without one, the name of what it runs and nothing after it: an artisan
     * command (`backup:run`) or a program's file name (`sync.sh`).
     *
     * A command line can hold anything: a variable set in front of it, a URL
     * with a key in it, a path that names the client. So nothing is copied
     * from one but a single name of letters, numbers and . _ : -, and a
     * command that has no such name is just "Scheduled task".
     */
    public static function label(Event $event): string
    {
        $description = trim((string) preg_replace('/\p{C}+/u', ' ', (string) $event->description));
        if ($description !== '') {
            return mb_substr($description, 0, 120);
        }

        if ($event->command === null) {
            return 'closure';
        }

        return self::name($event->command) ?? 'Scheduled task';
    }

    /**
     * The artisan command a command line runs, or else its program's file name.
     */
    private static function name(string $command): ?string
    {
        // Words as a shell would split them, so a quoted value with spaces stays one word.
        preg_match_all('/(?:[^\s"\']+|"[^"]*"|\'[^\']*\')+/', $command, $matches);
        $words = $matches[0];
        $bare = fn (string $word): string => str_replace(['"', "'", '\\'], ['', '', '/'], $word);

        // `php artisan backup:run --only-db`, wherever PHP and artisan live: the word after artisan.
        foreach ($words as $i => $word) {
            if (basename($bare($word)) === 'artisan' && ! str_contains($word, '://')) {
                $name = $bare($words[$i + 1] ?? '');
                if (preg_match('/^[A-Za-z][A-Za-z0-9:_.-]{0,119}$/', $name) === 1) {
                    return $name;
                }

                break;
            }
        }

        // Anything else: the program's file name, past any variables set in front of it.
        foreach ($words as $word) {
            if (preg_match('/^[A-Za-z_][A-Za-z0-9_]*=/', $word) === 1) {
                continue;
            }
            $name = basename($bare($word));

            return preg_match('/^[A-Za-z0-9][A-Za-z0-9._:-]{0,119}$/', $name) === 1 ? $name : null;
        }

        return null;
    }

    /**
     * Why the scheduler skipped a task that was due. Laravel says only that it
     * did (`ScheduledTaskSkipped`), and says it the same way for a paused
     * schedule, a held `withoutOverlapping()` lock and the task's own
     * condition (`when()`, `skip()`, `between()` and the like), so this asks
     * what Laravel asked, in the order it asked. SkyLock counts only a
     * condition as on time, so a skip it can't be sure of isn't sent: if the
     * cache can't be read, the task goes late as it always did.
     *
     * @return 'paused'|'overlapping'|'condition'|null
     */
    public static function skipReason(Event $event, Repository $cache): ?string
    {
        try {
            if (self::schedulePaused($cache) && ! $event->runsWhenPaused()) {
                return 'paused';
            }

            // withoutOverlapping() is itself a skip() on this lock.
            if ($event->withoutOverlapping && $event->mutex->exists($event)) {
                return 'overlapping';
            }
        } catch (Throwable) {
            return null;
        }

        return 'condition';
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

    /**
     * `schedule:pause` (Laravel 12.x and later), read the way `schedule:run` reads it.
     */
    private static function schedulePaused(Repository $cache): bool
    {
        return property_exists(Schedule::class, 'pausable')
            && Schedule::$pausable
            && (bool) $cache->get('illuminate:schedule:paused', false);
    }

    private static function timezone(Event $event): string
    {
        $timezone = $event->timezone ?? config('app.timezone', 'UTC');

        return $timezone instanceof \DateTimeZone ? $timezone->getName() : (string) $timezone;
    }
}
