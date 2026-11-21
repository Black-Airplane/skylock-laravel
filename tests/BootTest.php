<?php

use Illuminate\Support\Facades\Queue;

it('leaves the queue manager alone while it boots with a token', function () {
    // Before Laravel 13.x added QueueManager::createPayloadUsing(), a call
    // through the facade opened the default connection. On an app whose
    // connection driver is registered by its own provider, which boots after
    // this one, every artisan command then failed with "No connector for [...]".
    Queue::swap(new class
    {
        public function __call(string $method, array $arguments): never
        {
            throw new RuntimeException("The queue manager was used while booting: {$method}()");
        }
    });

    reporterConfigured();

    expect(true)->toBeTrue();
});
