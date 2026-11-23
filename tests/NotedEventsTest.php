<?php

use BlackAirplane\SkyLockReporter\Commands\ReportCommand;
use BlackAirplane\SkyLockReporter\LocalState;
use BlackAirplane\SkyLockReporter\QueuedOutcomes;
use BlackAirplane\SkyLockReporter\ScheduledTasks;
use BlackAirplane\SkyLockReporter\TaskEvents;
use Carbon\CarbonInterval;
use Illuminate\Console\Events\ScheduledTaskFinished;
use Illuminate\Console\Events\ScheduledTaskStarting;
use Illuminate\Console\Scheduling\Event;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Sleep;
use Illuminate\Support\Str;

/*
 * While SkyLock takes batches, the scheduler notes task events on the server,
 * and the minute report sends them at the app's own second of the minute
 * (0.6). Nothing in the scheduler waits on SkyLock, and what doesn't get
 * through is sent the next minute instead of being lost.
 */

beforeEach(function () {
    reporterConfigured();
    $this->travelTo(now()->utc()->setDate(2026, 11, 24)->setTime(12, 0, 0));
    Sleep::fake(syncWithCarbon: true);
    $this->daily = scheduledEvent("'/usr/bin/php' 'artisan' backup:run", '0 12 * * *');
    $this->labels = [ScheduledTasks::key($this->daily) => 'daily'];
});

/** One run of a task, as schedule:run tells it. */
function taskRan(Event $task, bool $fails = false): void
{
    event(new ScheduledTaskStarting($task));
    $task->exitCode = $fails ? 1 : 0;
    event(new ScheduledTaskFinished($task, 1.5));
}

function minuteReportRuns(): void
{
    test()->artisan('skylock:report')->assertSuccessful();
}

/**
 * SkyLock taking batches, answering each with the next of $answers (202 when
 * they run out), and returning the events of each batch it was sent.
 *
 * @param  list<int|null>  $answers  a status, or null for no answer at all
 */
function batchesTo(array $answers = []): ArrayObject
{
    $batches = new ArrayObject;
    Http::fake(function (Request $request) use ($batches, &$answers) {
        if (! str_ends_with($request->url(), '/task-events/batch')) {
            return Http::response(['accepted' => true, 'scheduleKnown' => true, 'stackKnown' => true, 'taskEventBatches' => true], 202);
        }
        $batches[] = $request['events'];
        $answer = $answers === [] ? 202 : array_shift($answers);

        return $answer === null ? Http::failedConnection()($request) : Http::response(['accepted' => $answer === 202], $answer);
    });

    return $batches;
}

it('notes task events on the server while SkyLock takes batches, and the scheduler sends nothing', function () {
    skyLock($this->labels, batches: true);
    skyLockTakesBatches();

    taskRan($this->daily);

    Http::assertNothingSent();
    expect(array_map(fn (array $event): array => array_keys($event), LocalState::takeNoted()))->toBe([
        ['id', 'key', 'event', 'at'],
        ['id', 'key', 'event', 'durationMs', 'at'],
    ]);
});

it('sends what was noted with the next minute report, after its health and schedule, in the order it happened', function () {
    $sent = skyLock($this->labels, batches: true);
    skyLockTakesBatches();
    taskRan($this->daily);
    taskRan($this->daily, fails: true);

    minuteReportRuns();

    expect($sent->getArrayCopy())->toBe(['daily:starting', 'daily:finished', 'daily:starting', 'daily:failed'])
        ->and(Http::recorded()->map(fn (array $pair): string => Str::after($pair[0]->url(), '/api/ingest/'))->last())->toBe('task-events/batch')
        ->and(Http::recorded()->map(fn (array $pair): string => Str::after($pair[0]->url(), '/api/ingest/'))->first())->toBe('health')
        ->and(LocalState::takeNoted())->toBe([]);
});

it('starts noting once SkyLock says it takes batches, and stops once it says it doesn’t', function () {
    $answer = new ArrayObject(['batches' => true]);
    $sent = new ArrayObject;
    Http::fake(function (Request $request) use ($answer, $sent) {
        if (str_ends_with($request->url(), '/task-events')) {
            $sent[] = $request['event'];
        }

        return Http::response(['accepted' => true, 'scheduleKnown' => true, 'stackKnown' => true, 'taskEventBatches' => $answer['batches']], 202);
    });

    taskRan($this->daily);
    expect($sent->getArrayCopy())->toBe(['starting', 'finished']);

    minuteReportRuns();
    app()->forgetInstance(TaskEvents::class);
    taskRan($this->daily);
    expect($sent->getArrayCopy())->toBe(['starting', 'finished'])
        ->and(LocalState::get('taskEventBatches'))->toBeTrue();

    // What was noted before SkyLock stopped taking batches goes one at a time with the next report.
    $answer['batches'] = false;
    minuteReportRuns();
    expect($sent->getArrayCopy())->toBe(['starting', 'finished', 'starting', 'finished'])
        ->and(LocalState::get('taskEventBatches'))->toBeFalse();
});

it('keeps a batch that doesn’t get through for the next report, ahead of what’s noted since, with the same ids', function () {
    $batches = batchesTo([null]);
    skyLockTakesBatches();
    taskRan($this->daily);
    minuteReportRuns();

    taskRan($this->daily, fails: true);
    $this->travel(1)->minute();
    minuteReportRuns();

    [$first, $second] = $batches->getArrayCopy();
    expect(array_column($second, 'event'))->toBe(['starting', 'finished', 'starting', 'failed'])
        ->and(array_slice(array_column($second, 'id'), 0, 2))->toBe(array_column($first, 'id'))
        ->and(LocalState::takeNoted())->toBe([]);
});

it('keeps what’s waiting while SkyLock is busy or down', function (?int $answer) {
    $batches = batchesTo([$answer]);
    skyLockTakesBatches();
    taskRan($this->daily);

    minuteReportRuns();
    minuteReportRuns();

    expect(array_map(fn (array $batch): array => array_column($batch, 'event'), $batches->getArrayCopy()))->toBe([['starting', 'finished'], ['starting', 'finished']]);
})->with(['no answer' => null, 'busy' => 429, 'timed out' => 408, 'broken' => 500, 'down' => 503]);

it('drops a batch SkyLock read and refused, which it would refuse every minute', function () {
    $batches = batchesTo([422]);
    skyLockTakesBatches();
    taskRan($this->daily);

    minuteReportRuns();
    minuteReportRuns();

    expect($batches)->toHaveCount(1)
        ->and(LocalState::takeNoted())->toBe([]);
});

it('sends at most a hundred events in a batch', function () {
    $batches = batchesTo();
    skyLockTakesBatches();
    foreach (range(1, 250) as $n) {
        LocalState::note(TaskEvents::noted(ScheduledTasks::key($this->daily), 'starting'));
    }

    minuteReportRuns();

    expect(array_map('count', $batches->getArrayCopy()))->toBe([100, 100, 50]);
});

it('leaves what it has no time left for to the next report, so a report ends before the next one starts', function () {
    $batches = new ArrayObject;
    Http::fake(function (Request $request) use ($batches) {
        if (str_ends_with($request->url(), '/task-events/batch')) {
            $batches[] = $request['events'];

            return Http::response(['accepted' => true], 202);
        }
        // SkyLock took its time over the health report.
        test()->travel(ReportCommand::SEND_FOR_SECONDS + 1)->seconds();

        return Http::response(['accepted' => true, 'scheduleKnown' => true, 'stackKnown' => true, 'taskEventBatches' => true], 202);
    });
    skyLockTakesBatches();
    taskRan($this->daily);

    minuteReportRuns();

    expect($batches)->toHaveCount(0)
        ->and(array_column(LocalState::takeNoted(), 'event'))->toBe(['starting', 'finished']);
});

it('sends what queued work did after what the scheduler noted, in the same batch', function () {
    $batches = batchesTo();
    skyLockTakesBatches();
    event(new ScheduledTaskStarting($this->daily));
    app(QueuedOutcomes::class)->add(ScheduledTasks::key($this->daily), 'finished', 4200);

    minuteReportRuns();

    expect(array_map(fn (array $event): array => [$event['event'], $event['durationMs'] ?? null], $batches[0]))->toBe([['starting', null], ['finished', 4200]]);
});

it('doesn’t send an event that waited two days: SkyLock has long heard of later runs', function () {
    $batches = batchesTo();
    skyLockTakesBatches();
    LocalState::note(TaskEvents::noted(ScheduledTasks::key($this->daily), 'starting'));
    $this->travel(LocalState::NOTED_KEEP_SECONDS + 1)->seconds();
    LocalState::note(TaskEvents::noted(ScheduledTasks::key($this->daily), 'finished'));

    minuteReportRuns();

    expect(array_column($batches[0], 'event'))->toBe(['finished']);
});

it('sends straight away when it can’t note an event', function () {
    $sent = skyLock($this->labels, batches: true);
    skyLockTakesBatches();
    // Something else is where the noted events would go.
    mkdir(LocalState::notedPath());

    taskRan($this->daily);

    expect($sent->getArrayCopy())->toBe(['daily:starting', 'daily:finished']);
    rmdir(LocalState::notedPath());
});

it('keeps noted events readable by the app’s own user alone', function () {
    if (PHP_OS_FAMILY === 'Windows') {
        $this->markTestSkipped('Windows has no file modes.');
    }

    LocalState::note(TaskEvents::noted(ScheduledTasks::key($this->daily), 'starting'));
    LocalState::keepNoted([TaskEvents::noted(ScheduledTasks::key($this->daily), 'finished')]);

    clearstatcache();
    expect(fileperms(LocalState::notedPath()) & 0777)->toBe(0600)
        ->and(fileperms(LocalState::keptPath()) & 0777)->toBe(0600);
});

it('waits for the app’s own second of the minute when the scheduler runs the report, and not when someone does', function () {
    batchesTo();
    $this->travelTo(now()->setTime(12, 0, 2));

    $this->artisan('skylock:report --spread')->assertSuccessful();
    Sleep::assertSlept(fn (CarbonInterval $wait): bool => (int) $wait->totalSeconds === ReportCommand::second(TOKEN) - 2);

    $this->artisan('skylock:report')->assertSuccessful();
    Sleep::assertSleptTimes(1);

    $report = collect(app(Schedule::class)->events())->first(fn (Event $event): bool => ScheduledTasks::isOwn($event));
    expect((string) $report->command)->toEndWith('skylock:report --spread');
});

it('doesn’t wait when it’s already past the app’s second', function () {
    batchesTo();
    $this->travelTo(now()->setTime(12, 0, 50));

    $this->artisan('skylock:report --spread')->assertSuccessful();

    Sleep::assertNeverSlept();
    Http::assertSentCount(2);
});

it('spreads apps across the minute, each always at the same second', function () {
    $seconds = collect(range(1, 3500))->map(fn () => ReportCommand::second('slr_'.Str::random(40)));

    expect($seconds->min())->toBe(ReportCommand::SPREAD_FROM)
        ->and($seconds->max())->toBe(ReportCommand::SPREAD_FROM + ReportCommand::SPREAD_SECONDS - 1)
        ->and($seconds->countBy()->min())->toBeGreaterThan(50)
        ->and(ReportCommand::second(TOKEN))->toBe(ReportCommand::second(TOKEN));
});
