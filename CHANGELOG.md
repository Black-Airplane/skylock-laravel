# Changelog

## 0.4.3

- Skipped tasks are reported, with why. Laravel's scheduler skips a task that's due when its own condition says not to run (`when()`, `skip()`, `between()` and the like), when an earlier run still holds its `withoutOverlapping()` lock (which Laravel checks as a `skip()`), or when the schedule is paused, and says only that it skipped it, the same way for all three. SkyLock heard nothing, so a task meant for the first Sunday of each month (a weekly cron and a `when()`) looked late on every other Sunday. Now the reporter asks what Laravel asked, in the same order, and sends `condition`, `overlapping` or `paused`.
- SkyLock counts only `condition` as on time. A held lock or a paused schedule still makes the task late, as before, and the outage now says which.
- A skip the reporter can't explain (the cache can't be read) isn't sent, so the task goes late as it always did. A task that repeats within the minute has its skips sent once a minute, and again when the reason changes. The reporter's own report is never reported.
- Tasks limited to other environments (`->environments('production')`) are left out of the schedule a reporter sends: they never run in its environment, so SkyLock expected them there forever.
- The reporter stops when SkyLock revokes its token. When an application is retired in SkyLock, or its client offboarded, SkyLock answers `410 Gone` with `"revoked": true`; the reporter then sends nothing (its health checks don't run, task events and queued work aren't sent or kept), logs a warning once, and checks once a day whether it's been restored. A new token, or `skylock:test`, resumes it at once. A `410` without `revoked`, a `401` or an outage changes nothing. Before, it tried again every minute, forever.
- SkyLock takes skips from November 8, 2026, and answers revoked tokens `410` from November 10, 2026.

## 0.4.2

- A pending restart now says since when and what it's for. On Debian and Ubuntu, the reporter sends when the restart flag was last written (each install that needs a restart writes it again, so it's "at least since") and whether a new kernel is among the packages waiting on it: one boolean, never the package names. SkyLock dates the restart from the server instead of from when it first heard, and says when it's a kernel update.
- When the reporter can't look for the restart flag (open_basedir hides `/var/run`), it sends null, not "no restart needed", so it can never close a finding it couldn't check.
- On Ubuntu, the running kernel's build and flavour (`5.15.0-125.135`, `generic`), from `/proc/version_signature`, the line Ubuntu writes for exactly this. SkyLock looks up what that build is missing compared with the newest one Ubuntu has published, and ranks it like any package's vulnerabilities. A custom kernel, or one it can't read, is sent as null.
- Still never runs a command: all of it comes from plain files (`/var/run/reboot-required`, `/var/run/reboot-required.pkgs`, `/proc/version_signature`).

## 0.4.1

- Queue workers no longer wait on SkyLock. In 0.4.0 the worker that ran a scheduled task's job told SkyLock how it went before taking its next job, so every run of a frequent task gained a few hundred milliseconds, counted as the job's own time in Nightwatch and the like, and up to the three-second timeout when SkyLock was slow. Now the worker notes it in the app's cache and the next minute report sends it, within a minute. SkyLock judges a task late by when it next starts, which the scheduler still sends as it happens, so nothing it decides changes.
- While SkyLock can't be reached, those notes wait for the next report that gets through, for up to two days (the newest 500).
- A cache store without locks can't share the notes safely, so there the worker still sends straight away.
- The minute report reads the cache once more, to look for what the workers noted.
- Nothing changes for SkyLock: it receives the same events.

## 0.4.0

- Queued jobs report how a scheduled task's work really went. Before, a task like `Schedule::job(new NightlySync)` was reported finished as soon as its job was queued, so a job that failed or was killed on the queue worker every night still looked fine in SkyLock. Now every job queued while a scheduled task runs carries the task's key, and so does every job those jobs queue in turn. A task whose run queued exactly one job is reported finished when that job finishes, with its running time. Any job in the task's work that fails for good, or is killed for running too long, reports the task failed, once per run.
- Other tasks are reported as before. Jobs queued outside a scheduled task carry nothing.
- The scheduler and the queue workers note each run's progress in the app's cache, so they need the same cache store.
- Nothing changes for SkyLock: it receives the same three events.

## 0.3.4

- `skylock:test` and the README send people to the application's Settings tab for the token (it moved there from the Status tab), and say an Owner or Admin creates it.

## 0.3.3

- Quiet in Laravel Nightwatch. Nightwatch recorded every minute's report in full (the scheduled task, its own process, the request to SkyLock, five cache calls and three to eleven queries), 11 to 19 events a minute, or 475,000 to 820,000 a month for an app nobody uses. Now it records one report in a hundred, in `schedule:run` and in the report's own process; a report it skips is skipped whole, and one that throws is always recorded. `SKYLOCK_REPORTER_NIGHTWATCH_SAMPLE_RATE` changes the share.
- Task events and the web PHP note are kept off the app's own tasks and requests in Nightwatch.
- Each report does less: the schedule's hash and what the app runs on are kept in a file in `storage/framework/cache` instead of the app's cache (two fewer cache reads a minute, and two fewer queries where the cache is the database); the cache check writes a value only that run knows and reads it back, without deleting it; and failed jobs are counted without first asking whether their table exists.
- What the app runs on is read again on a server's first report after upgrading, and the schedule sent once, since neither is in the file yet.
- Nothing changes for SkyLock: it receives the same reports.

## 0.3.2

- Whether an error monitor is configured is sent with every minute report, not only with what the app runs on, so a deploy that adds Nightwatch, Sentry or the like shows in SkyLock within a minute instead of up to an hour.
- What the app runs on is read again after every deploy (a new commit, deploy id, dependencies or cached config), not only once an hour.
- The minute report says when what the app runs on was last read, so SkyLock can say how fresh it is.
- When someone asks SkyLock to check again, SkyLock asks in its reply to the next minute report, and the reporter reads what the app runs on afresh and sends it. Older SkyLocks never ask.

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
