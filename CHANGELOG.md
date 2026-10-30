# Changelog

## 0.2.0

- The minute report also says whether debug mode is on and whether the app thinks it's running in production: two booleans from `APP_DEBUG` and `APP_ENV`, never their values. SkyLock uses them for BA-SUR-003 (debug mode is off in production). SkyLock 0.1 reporters keep working; their apps show this as not measured.

## 0.1.0

The first release.

- A health report every minute: database, cache, queue size, oldest waiting job (database queues), failed jobs in the last hour, disk used, and spatie/laravel-health results when it's installed.
- Scheduled tasks are read from the app's schedule and sent when they change. Each start, finish and failure is reported with its duration.
- `skylock:test`, `skylock:sync-schedule` and `skylock:report` commands.
- Laravel 12 and 13 on PHP 8.2 and later. Laravel 11 is end of life and every release has open security advisories, so it isn't supported.
