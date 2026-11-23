<?php

use Illuminate\Support\Facades\Queue;

// Packages boot before the app's own providers. On Laravel 12, and on 13
// before 13.30, a call through the Queue facade that the queue manager has no
// method for goes on to the default connection and opens it. On an app whose
// connection driver one of its providers registers in boot(), every artisan
// command then failed with "No connector for [...]".

it('leaves the queue manager alone while it boots with a token', function () {
    Queue::swap(new class
    {
        public function __call(string $method, array $arguments): never
        {
            throw new RuntimeException("The queue manager was used while booting: {$method}()");
        }
    });

    expect(fn () => reporterConfigured())->not->toThrow(RuntimeException::class);
});

it('boots with a token before the app registers its queue driver', function () {
    config(['queue.default' => 'bulk', 'queue.connections.bulk' => ['driver' => 'sqs-bulk']]);

    reporterConfigured();

    expect(app('queue')->connected('bulk'))->toBeFalse();
});
