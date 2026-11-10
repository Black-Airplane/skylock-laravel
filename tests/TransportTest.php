<?php

use BlackAirplane\SkyLockReporter\LocalState;
use BlackAirplane\SkyLockReporter\Revocation;
use BlackAirplane\SkyLockReporter\SkyLockClient;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/*
 * How the reporter reaches SkyLock: over https only, to the address it was
 * given and nowhere that address sends it, and what it remembers on disk is
 * the app's alone to read.
 */

it('sends nothing over plain http, so the token never crosses the network unencrypted', function () {
    reporterConfigured();
    app()->detectEnvironment(fn (): string => 'production');
    config(['skylock-reporter.url' => 'http://skylock.example']);
    Http::fake(['*' => Http::response(['accepted' => true], 202)]);
    Log::spy();

    expect(app(SkyLockClient::class)->send('POST', 'health', ['checks' => []]))->toBeNull();
    $this->artisan('skylock:report')->assertSuccessful();
    $this->artisan('skylock:test')->expectsOutputToContain('must start with https://')->assertFailed();
    $this->artisan('skylock:sync-schedule')->assertFailed();

    Http::assertNothingSent();
    Log::shouldHaveReceived('warning')->once()->withArgs(fn (string $message): bool => str_contains($message, 'https://') && ! str_contains($message, TOKEN));
});

it('needs https whatever the address looks like', function (string $url) {
    reporterConfigured();
    app()->detectEnvironment(fn (): string => 'production');
    config(['skylock-reporter.url' => $url]);
    Http::fake(['*' => Http::response(['accepted' => true], 202)]);

    expect(app(SkyLockClient::class)->send('POST', 'health', ['checks' => []]))->toBeNull();
    Http::assertNothingSent();
})->with(['http://getskylock.com', 'HTTP://getskylock.com', 'getskylock.com', '//getskylock.com', 'ftp://getskylock.com', 'https//getskylock.com', '']);

it('lets a developer’s own machine use http', function (string $environment) {
    reporterConfigured();
    app()->detectEnvironment(fn (): string => $environment);
    config(['skylock-reporter.url' => 'http://skylock.test']);
    Http::fake(['*' => Http::response(['accepted' => true], 202)]);

    expect(app(SkyLockClient::class)->send('POST', 'health', ['checks' => []]))->toBe(['accepted' => true]);
    Http::assertSent(fn (Request $request): bool => $request->url() === 'http://skylock.test/api/ingest/health');
})->with(['local', 'testing']);

it('never follows a redirect: the report goes to SkyLock or nowhere', function (int $status) {
    reporterConfigured();
    Http::fake([
        'skylock.example/*' => Http::response('', $status, ['Location' => 'https://elsewhere.example/api/ingest/health']),
        'elsewhere.example/*' => Http::response(['accepted' => true], 202),
    ]);

    expect(app(SkyLockClient::class)->send('POST', 'health', ['checks' => []]))->toBeNull();

    Http::assertSentCount(1);
    Http::assertNotSent(fn (Request $request): bool => str_contains($request->url(), 'elsewhere.example'));
})->with([301, 302, 307, 308]);

it('keeps what it remembers readable by the app’s own user alone', function () {
    if (PHP_OS_FAMILY === 'Windows') {
        $this->markTestSkipped('Windows has no file modes.');
    }

    // A file an older version left, readable by everyone.
    file_put_contents(LocalState::path(), '{"schedule":"abc"}');
    chmod(LocalState::path(), 0644);

    LocalState::put('stack', ['until' => 1]);

    clearstatcache();
    expect(fileperms(LocalState::path()) & 0777)->toBe(0600)
        ->and(LocalState::get('schedule'))->toBe('abc')
        ->and(glob(LocalState::path().'.*'))->toBe([]);
});

it('remembers a revoked token without writing down the hash SkyLock knows it by', function () {
    reporterConfigured();

    Revocation::mark();

    $kept = (string) file_get_contents(LocalState::path());
    expect(Revocation::active())->toBeTrue()
        ->and($kept)->not->toContain(hash('sha256', TOKEN))
        ->and($kept)->not->toContain(TOKEN);

    // A marker an older version wrote (the plain hash) isn't taken for this one: it asks SkyLock again.
    LocalState::put('revoked', ['token' => hash('sha256', TOKEN), 'checkAfter' => now()->addDay()->toIso8601String()]);
    expect(Revocation::active())->toBeFalse();
});
