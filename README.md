# SkyLock reporter for Laravel

Reports a Laravel app's health and scheduled tasks to [SkyLock](https://getskylock.com), Black Airplane's monitoring platform. SkyLock checks your site from outside; this tells it what's happening inside: whether the database, cache and queue work, whether the scheduler is running, and whether each scheduled task ran on time.

## Requirements

PHP 8.2 or later and Laravel 12 or 13, with the scheduler running (`schedule:run` every minute, as Laravel's own docs set it up).

## Install

```bash
composer require blackairplane/skylock-laravel
```

Add the token from the application's **Status** tab in SkyLock to `.env`:

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

- **Every minute:** your PHP, Laravel and package versions; whether debug mode is on and whether the app thinks it's running in production (two booleans, from `APP_DEBUG` and `APP_ENV`, never their values); and a list of health checks, each a name, a status (pass, warn or fail) and sometimes a number with a unit:
  - Database: can it run `select 1`.
  - Cache: can it write and read a value.
  - Queue size, and the oldest waiting job (database queues).
  - Failed jobs in the last hour.
  - Disk used, as a percentage.
  - Results from [spatie/laravel-health](https://github.com/spatie/laravel-health), if it's installed: name and status only.
- **When your schedule changes:** each scheduled task's cron expression, time zone, a label (its `->description()`, or just the command's name, such as `backup:run`) and a key that's a hash of its full command.
- **When a scheduled task starts, finishes or fails:** its key, and how long it took.

It never sends error messages, exception text, hosts, queries, environment values, command arguments or anything a user entered. A report that can't get through is dropped after three seconds; the reporter never throws, and it never slows your app down.

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
