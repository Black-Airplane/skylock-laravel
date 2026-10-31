# Changelog

## 0.3.1

- A task that repeats within the minute (`everyFifteenSeconds()` and the like) is reported for its first run each minute, every failure, and the first success after one, instead of every run. SkyLock judges lateness by the minute, so the other runs told it nothing; a 15-second task sent 11,520 requests a day and now sends about 2,880.
- Task events are sent from inside the scheduler, before and after each task, and each could wait three seconds for an unreachable SkyLock. Now, after one doesn't get through, tasks that run again within two minutes skip SkyLock for a minute; their next run reports them, well inside SkyLock's five minutes' grace. Failures, and tasks that run less often, are always sent, because missing them would leave SkyLock wrong until their next run.
- Nothing changes for SkyLock: it receives the same events, fewer of them.

## 0.3.0

- What the app runs on, sent when it changes: the operating system and, on Debian and Ubuntu, waiting security updates, a pending restart, automatic updates and Ubuntu Pro; the PHP running the scheduler and the PHP serving web requests; Laravel, the default database and Redis or Valkey; the deployed commit; the hosting; a server key (a hash, never the machine id); and whether an error monitor is configured. SkyLock uses it for BA-RUN-001, BA-RUN-002 and BA-MON-001. Older SkyLocks never ask for it.
- About one web request in 500 notes which PHP served it, after the response has gone, with one cache write. `SKYLOCK_REPORTER_WEB_RUNTIME=false` turns it off. It never runs commands.
- `skylock:test` shows the stack it would send, and sends it.

## 0.2.0

- The minute report also says whether debug mode is on and whether the app thinks it's running in production: two booleans from `APP_DEBUG` and `APP_ENV`, never their values. SkyLock uses them for BA-SUR-003 (debug mode is off in production). SkyLock 0.1 reporters keep working; their apps show this as not measured.

## 0.1.0

The first release.

- A health report every minute: database, cache, queue size, oldest waiting job (database queues), failed jobs in the last hour, disk used, and spatie/laravel-health results when it's installed.
- Scheduled tasks are read from the app's schedule and sent when they change. Each start, finish and failure is reported with its duration.
- `skylock:test`, `skylock:sync-schedule` and `skylock:report` commands.
- Laravel 12 and 13 on PHP 8.2 and later. Laravel 11 is end of life and every release has open security advisories, so it isn't supported.
