<?php

/*
 * Just enough of spatie/laravel-health for the reporter to find: the result
 * store it asks for, by the name it asks for it. The package itself isn't
 * installed for the tests.
 */

namespace Spatie\Health\ResultStores;

interface ResultStore
{
    public function latestResults(): ?object;
}
