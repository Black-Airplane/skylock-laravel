<?php

use BlackAirplane\SkyLockReporter\LocalState;
use BlackAirplane\SkyLockReporter\QueuedOutcomes;
use BlackAirplane\SkyLockReporter\Revocation;
use Illuminate\Console\Events\ScheduledTaskFailed;
use Illuminate\Console\Events\ScheduledTaskStarting;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/*
 * When SkyLock revokes the token (its application was retired, or its client
 * offboarded), it answers 410 with `revoked: true` and the reporter stops.
 */

const REVOKED_ANSWER = ['message' => 'SkyLock no longer accepts reports for this application.', 'revoked' => true];

/**
 * Fakes SkyLock answering every request with $status, and returns the paths it was sent.
 *
 * @param  ArrayObject<string, mixed>  $answer  `status` and `body` can change mid-test
 */
function skyLockAnswering(ArrayObject $answer): ArrayObject
{
    $sent = new ArrayObject;
    Http::fake(function (Request $request) use ($sent, $answer) {
        $sent[] = $request->method().' '.str_replace('https://skylock.example/api/ingest/', '', $request->url());

        return Http::response($answer['body'], $answer['status']);
    });

    return $sent;
}

beforeEach(function () {
    reporterConfigured();
    app(Schedule::class)->command('backup:run')->daily();
    $this->answer = new ArrayObject(['status' => 410, 'body' => REVOKED_ANSWER]);
    $this->sent = skyLockAnswering($this->answer);
});

it('stops sending once SkyLock says the token is revoked', function () {
    Log::spy();

    $this->artisan('skylock:report')->assertSuccessful();
    $this->artisan('skylock:report')->assertSuccessful();
    event(new ScheduledTaskStarting(scheduledEvent("'/usr/bin/php' 'artisan' backup:run")));
    event(new ScheduledTaskFailed(scheduledEvent("'/usr/bin/php' 'artisan' backup:run"), new RuntimeException('Broken')));
    app(QueuedOutcomes::class)->add('backup', 'finished', 12);

    // One report, refused. Not the next minute's, not a task's start or even its failure, and queued work isn't kept for later.
    expect($this->sent->getArrayCopy())->toBe(['POST health'])
        ->and(Cache::get(QueuedOutcomes::CACHE_KEY))->toBeNull()
        ->and(Revocation::active())->toBeTrue();
    Log::shouldHaveReceived('warning')->once()->withArgs(fn (string $message): bool => str_contains($message, 'no longer accepts this token'));
});

it('checks once a day whether it’s been restored', function () {
    $this->artisan('skylock:report')->assertSuccessful();

    $this->travel(23)->hours();
    $this->artisan('skylock:report')->assertSuccessful();
    expect($this->sent->getArrayCopy())->toBe(['POST health']);

    // Still revoked a day on: the next check is a day after that.
    $this->travel(61)->minutes();
    $this->artisan('skylock:report')->assertSuccessful();
    $this->travel(1)->minutes();
    $this->artisan('skylock:report')->assertSuccessful();
    expect($this->sent->getArrayCopy())->toBe(['POST health', 'POST health']);

    // Restored: the check is accepted (with the schedule it never got to send), and it's back to every minute.
    $this->answer['status'] = 202;
    $this->answer['body'] = ['accepted' => true, 'scheduleKnown' => true, 'stackKnown' => true];
    $this->travel(1)->days();
    $this->artisan('skylock:report')->assertSuccessful();
    $this->travel(1)->minutes();
    $this->artisan('skylock:report')->assertSuccessful();
    event(new ScheduledTaskStarting(scheduledEvent("'/usr/bin/php' 'artisan' backup:run")));

    expect($this->sent->getArrayCopy())->toBe(['POST health', 'POST health', 'POST health', 'PUT schedule', 'POST health', 'POST task-events'])
        ->and(Revocation::active())->toBeFalse()
        ->and(LocalState::get('revoked'))->toBeNull();
});

it('resumes at once with a new token', function () {
    $this->artisan('skylock:report')->assertSuccessful();
    $this->answer['status'] = 202;
    $this->answer['body'] = ['accepted' => true, 'scheduleKnown' => true, 'stackKnown' => true];

    config(['skylock-reporter.token' => 'slr_'.str_repeat('n', 40)]);
    $this->artisan('skylock:report')->assertSuccessful();

    expect($this->sent->getArrayCopy())->toBe(['POST health', 'POST health', 'PUT schedule']);
});

it('stops only for SkyLock’s own answer, never a proxy’s 410, a 401 or an outage', function (int $status, array $body) {
    $this->answer['status'] = $status;
    $this->answer['body'] = $body;

    $this->artisan('skylock:report')->assertSuccessful();
    $this->travel(1)->minutes();
    $this->artisan('skylock:report')->assertSuccessful();

    expect($this->sent->getArrayCopy())->toBe(['POST health', 'POST health'])
        ->and(Revocation::active())->toBeFalse();
})->with([
    'a 410 that doesn’t say revoked' => [410, ['message' => 'Gone']],
    'an unknown token' => [401, ['message' => 'Missing or invalid reporter token.']],
    'SkyLock down' => [503, []],
]);

it('says so when skylock:test finds the token revoked, and resumes when it’s been restored', function () {
    $this->artisan('skylock:report')->assertSuccessful();

    // It always sends: this is how someone finds out.
    $this->artisan('skylock:test')
        ->expectsOutputToContain('SkyLock no longer accepts this token')
        ->assertFailed();
    $this->artisan('skylock:sync-schedule')
        ->expectsOutputToContain('SkyLock no longer accepts this token')
        ->assertFailed();

    $this->answer['status'] = 202;
    $this->answer['body'] = ['accepted' => true, 'scheduleKnown' => true, 'stackKnown' => true];
    $this->artisan('skylock:test')->assertSuccessful();
    $this->artisan('skylock:report')->assertSuccessful();

    expect($this->sent->getArrayCopy())->toBe(['POST health', 'POST health', 'PUT schedule', 'POST health', 'PUT stack', 'POST health', 'PUT schedule'])
        ->and(Revocation::active())->toBeFalse();
});
