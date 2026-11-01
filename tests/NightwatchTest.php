<?php

use BlackAirplane\SkyLockReporter\NightwatchSampling;
use BlackAirplane\SkyLockReporter\ScheduledTasks;
use BlackAirplane\SkyLockReporter\WebRuntime;
use Illuminate\Console\Events\CommandStarting;
use Illuminate\Console\Events\ScheduledTaskFinished;
use Illuminate\Console\Scheduling\Event;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Support\Facades\Http;
use Laravel\Nightwatch\Core;
use Laravel\Nightwatch\Facades\Nightwatch;
use Laravel\Nightwatch\NightwatchServiceProvider;
use Symfony\Component\Console\Input\ArrayInput;
use Symfony\Component\Console\Output\NullOutput;

/**
 * Stands in for Nightwatch's core and keeps every call made to it. Like
 * Nightwatch, it runs what it's asked to ignore.
 */
function nightwatch(): object
{
    $recorder = new class
    {
        /** @var list<array{0: string, 1: array<mixed>}> */
        public array $calls = [];

        /** @param  array<mixed>  $arguments */
        public function __call(string $method, array $arguments): mixed
        {
            $this->calls[] = [$method, $arguments];

            return $method === 'ignore' ? $arguments[0]() : null;
        }

        /** @return list<string> */
        public function methods(): array
        {
            return array_column($this->calls, 0);
        }
    };
    Nightwatch::swap($recorder);

    return $recorder;
}

function theReport(): Event
{
    return collect(app(Schedule::class)->events())->first(fn (Event $event): bool => ScheduledTasks::isOwn($event));
}

function commandStarting(string $command): void
{
    event(new CommandStarting($command, new ArrayInput([]), new NullOutput));
}

beforeEach(fn () => WebRuntime::forget());

it('has Nightwatch record the minute report one run in a hundred, as schedule:run starts or skips it', function () {
    $nightwatch = nightwatch();
    reporterConfigured();
    $report = theReport();

    expect($nightwatch->calls)->toBe([['sampleScheduledTask', [$report, 0.01]]]);
});

it('samples the report’s own process too, after Nightwatch has', function () {
    $nightwatch = nightwatch();
    reporterConfigured();
    $nightwatch->calls = [];

    commandStarting('skylock:report');

    expect($nightwatch->calls)->toBe([['sample', [0.01]]]);
});

it('leaves other commands, and a report another command calls, to Nightwatch’s own rates', function (string $first) {
    $nightwatch = nightwatch();
    reporterConfigured();
    $nightwatch->calls = [];

    commandStarting($first);
    commandStarting('skylock:report');

    expect($nightwatch->methods())->not->toContain('sample');
})->with(['schedule:run', 'migrate', 'skylock:test']);

it('takes the rate from the config, kept between 0 and 1', function (mixed $setting, float $rate) {
    config(['skylock-reporter.nightwatch_sample_rate' => $setting]);

    expect(NightwatchSampling::rate())->toBe($rate);
})->with([
    ['0', 0.0],
    ['0.25', 0.25],
    [1, 1.0],
    [5, 1.0],
    [-1, 0.0],
    ['often', 0.01],
    [null, 0.01],
]);

it('keeps task events off the app’s own tasks in Nightwatch, and still sends them', function () {
    $nightwatch = nightwatch();
    reporterConfigured();
    Http::fake(['*' => Http::response(['accepted' => true], 202)]);

    event(new ScheduledTaskFinished(scheduledEvent("'/usr/bin/php' 'artisan' backup:run"), 1.5));

    expect($nightwatch->methods())->toContain('ignore');
    Http::assertSentCount(1);
});

it('keeps the web PHP note off the request in Nightwatch', function () {
    $nightwatch = nightwatch();
    config(['cache.default' => 'file']);

    WebRuntime::sample(always: true);

    expect($nightwatch->methods())->toBe(['ignore'])
        ->and(WebRuntime::read())->toBe(['version' => PHP_VERSION, 'sapi' => PHP_SAPI]);
});

it('does nothing about Nightwatch in an app that doesn’t use it', function () {
    reporterConfigured();
    Http::fake(['*' => Http::response(['accepted' => true, 'scheduleKnown' => true, 'stackKnown' => true], 202)]);

    expect(theReport())->not->toBeNull()
        ->and(NightwatchSampling::ignore(fn () => 'ran'))->toBe('ran');
    $this->artisan('skylock:report')->assertSuccessful();
    Http::assertSent(fn ($request): bool => str_ends_with($request->url(), '/health'));
});

it('works with Nightwatch itself, not just a stand-in', function (float $rate, bool $sampled) {
    config(['nightwatch.enabled' => true, 'nightwatch.token' => null, 'skylock-reporter.nightwatch_sample_rate' => $rate]);
    $this->app->register(NightwatchServiceProvider::class);
    reporterConfigured();

    app(Core::class)->configureScheduledTaskSampling(theReport());
    $scheduled = Nightwatch::sampling();
    Nightwatch::sample(1 - $rate);
    NightwatchSampling::command();

    expect($scheduled)->toBe($sampled)
        ->and(Nightwatch::sampling())->toBe($sampled)
        ->and(NightwatchSampling::ignore(fn () => Nightwatch::paused()))->toBeTrue()
        ->and(Nightwatch::paused())->toBeFalse();
})->with([[0.0, false], [1.0, true]]);
