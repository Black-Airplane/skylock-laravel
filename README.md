# SkyLock reporter for Laravel

Reports a Laravel app's health and scheduled tasks to [SkyLock](https://getskylock.com), Black Airplane's monitoring platform. SkyLock checks your site from outside; this tells it what's happening inside: whether the database, cache and queue work, whether the scheduler is running, and whether each scheduled task ran on time.

## Requirements

PHP 8.2 or later and Laravel 12 or 13, with the scheduler running (`schedule:run` every minute, as Laravel's own docs set it up).

## Install

```bash
composer require blackairplane/skylock-laravel
```

It does nothing until it has a token, so it's safe to merge first. An Owner or Admin creates the token in SkyLock, on the application's **Settings** tab under **From inside the app**. Add it to production's `.env`:

```dotenv
SKYLOCK_REPORTER_URL=https://getskylock.com
SKYLOCK_REPORTER_TOKEN=slr_...
```

Then check it works:

```bash
php artisan skylock:test
```

That's all. The app's scheduler already runs `schedule:run` every minute, and the package adds its report to it.

## What it sends

Only these, and nothing else:

- **Every minute:** your PHP, Laravel and package versions; whether debug mode is on and whether the app thinks it's running in production (two booleans, from `APP_DEBUG` and `APP_ENV`, never their values); whether an error monitor is configured (one boolean); when it last read what the app runs on; and a list of health checks, each a name, a status (pass, warn or fail) and sometimes a number with a unit:
  - Database: can it run `select 1`.
  - Cache: can it write and read a value.
  - Queue size, and the oldest waiting job (database queues).
  - Failed jobs in the last hour.
  - Disk used, as a percentage.
  - Results from [spatie/laravel-health](https://github.com/spatie/laravel-health), if it's installed: name and status only.
- **What the app runs on, when it changes** (read at most once an hour, again after every deploy, and straight away when someone asks SkyLock to check again; SkyLock asks for it when it doesn't have the latest):
  - The operating system's ID and version (`ubuntu`, `22.04`) and whether it's a container.
  - On Debian and Ubuntu: how many security updates are waiting (the count update-notifier keeps), whether a restart is needed to finish installing updates (since when, and whether it's for a new kernel: a yes or no, never package names), which kernel is running (Ubuntu: its build and flavour), whether security updates install themselves, and whether Ubuntu Pro's extended support is on.
  - The PHP running the scheduler, and the PHP serving web requests (about one web request in 500 notes its version after the response has gone; set `SKYLOCK_REPORTER_WEB_RUNTIME=false` to turn that off).
  - Laravel's version; the default database's product and version (`mysql`, `8.0.36`); Redis or Valkey's version when the cache, queue or sessions use it.
  - The deployed commit (`SKYLOCK_COMMIT`, a platform's own variable, or the git checkout).
  - The hosting, from a fixed list (`laravel-cloud`, `forge`, `vapor`, `render`…).
  - A server key: a hash of the machine id, the same for every app on the server, so SkyLock can tell which apps share one. The machine id itself never leaves the server.
  - Whether an error monitor (Sentry, Bugsnag, Flare, Honeybadger, Rollbar, Nightwatch) is configured: one boolean.

  It reads plain files and asks PHP and the app's own connections; it never runs a command. Anything it can't read is sent as nothing.
- **When your schedule changes:** each scheduled task's cron expression, time zone, a label (its `->description()`, or just the command's name, such as `backup:run`) and a key that's a hash of its full command.
- **When a scheduled task starts, finishes or fails:** its key, and how long it took. A task that repeats within the minute (`everyFifteenSeconds()` and the like) is reported for its first run each minute, every failure, and the first success after one.
- **When the queued work a scheduled task started finishes or fails:** the same events, noted by the queue worker and sent with the next minute report. See [Queued jobs](#queued-jobs).

### Queued jobs

`Schedule::job(new NightlySync)` finishes the moment the job is queued, and the real work happens later on a queue worker, where it can fail or be killed for running too long without the scheduler knowing. So every job queued while a scheduled task runs carries the task's key in its payload, and so does every job those jobs queue, however far the work fans out.

- A task whose run queued exactly one job (the `Schedule::job` shape) is reported finished when that job finishes, with the job's running time. A job released to wait (a rate limit, say) isn't finished until it really is.
- Any job carrying a task's key that fails for good, or is killed for running too long, reports the task failed. That's once per run, however many of its jobs fail.
- Other tasks are reported as before, and the jobs they queue still report failures.

Workers don't wait on SkyLock. A worker notes how the work went in the app's cache and takes its next job, and the next minute report sends what was noted, so it reaches SkyLock within a minute. SkyLock judges a task late by when it next starts, which the scheduler sends as it happens, so that minute changes nothing it decides. While SkyLock can't be reached, the notes wait for the next report that gets through (for up to two days, the newest 500). A cache store without locks can't share them safely, so there each worker sends straight away instead.

The payload gains a `skylock` entry (the task key, a random run id, and how far out the job is). The scheduler and the workers note a run's progress in the app's cache for up to two days, so both need the same cache store, as Laravel's scheduler already does for its own locks.

It never sends error messages, exception text, hostnames, addresses, connection names, queries, environment variables (names or values), command arguments or anything a user entered. A report that can't get through is dropped after three seconds, and the reporter never throws. Task events are sent from inside the scheduler, so after one doesn't get through, tasks that run again within two minutes skip SkyLock for a minute (their next run reports them): an unreachable SkyLock costs your scheduler about one timeout a minute, however many tasks it runs. Failures, and tasks that run less often, are always sent.

## Commands

- `php artisan skylock:test` shows exactly what would be sent, and sends one report.
- `php artisan skylock:sync-schedule` sends the schedule now, e.g. from a deploy script. It also happens automatically.
- `php artisan skylock:report` is the minute report; the scheduler runs it for you.

## Configuration

The defaults suit most apps. To change when the queue or disk counts as a problem, or how long the reporter waits for SkyLock, publish the config:

```bash
php artisan vendor:publish --tag=skylock-reporter
```

| Setting | Default | Meaning |
| --- | --- | --- |
| `thresholds.queue_size` | 1000 | Warn above this many waiting jobs |
| `thresholds.oldest_job_seconds` | 900 | Warn when a job has waited longer than this |
| `thresholds.disk_used_warn` | 90 | Warn at this percentage of disk used |
| `thresholds.disk_used_fail` | 98 | Fail at this percentage of disk used |
| `timeout` | 3 | Seconds to wait for SkyLock before giving up |
| `nightwatch_sample_rate` | 0.01 | The share of minute reports Laravel Nightwatch records (`SKYLOCK_REPORTER_NIGHTWATCH_SAMPLE_RATE`) |

Between minutes the reporter keeps two things on the server, in `storage/framework/cache/skylock-reporter.json`: the schedule's hash and what the app runs on (the same facts it sends). It uses the app's cache only to check the cache works, and to note the web PHP.

## With Laravel Nightwatch

Every minute's report is much like the last, and Nightwatch would record each one in full: the scheduled task, the report's own process, the request to SkyLock, its cache calls and its queries, about 16,000 events a day before anyone uses the app. So the reporter has Nightwatch record one report in a hundred (about 14 a day, enough to see how long they take). A report Nightwatch skips is skipped whole, and one that throws is always recorded. Set `SKYLOCK_REPORTER_NIGHTWATCH_SAMPLE_RATE` between 0 and 1 to change it.

Task events and the web PHP note are sent from inside your app's own tasks and requests, so Nightwatch doesn't record them there: your tasks' records show your work, not SkyLock's. Without Nightwatch none of this does anything.

Without `SKYLOCK_REPORTER_TOKEN` the package does nothing at all, so it's safe to install everywhere and set the token only in production.

## Not Laravel?

Use a heartbeat instead: add one to a service in SkyLock and have your job request its URL when it finishes (`curl -fsS <url>`), or `<url>/fail` when it fails.

## Development

```bash
composer install
composer test      # Pest, on Orchestra Testbench
composer lint      # Pint
composer analyse   # PHPStan (Larastan), level 8
```

## Security

Report vulnerabilities privately; see [SECURITY.md](SECURITY.md).

## License

MIT. See [LICENSE](LICENSE).
