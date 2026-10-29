# Changelog

## 0.1.0

The first release.

- A health report every minute: database, cache, queue size, oldest waiting job (database queues), failed jobs in the last hour, disk used, and spatie/laravel-health results when it's installed.
- Scheduled tasks are read from the app's schedule and sent when they change. Each start, finish and failure is reported with its duration.
- `skylock:test`, `skylock:sync-schedule` and `skylock:report` commands.
- Laravel 11, 12 and 13 on PHP 8.2 and later.
