<?php

use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/*
|--------------------------------------------------------------------------
| Test Case bindings
|--------------------------------------------------------------------------
| Unit tests get the base TestCase. Feature tests additionally use
| RefreshDatabase, running migrations against the in-memory SQLite connection
| configured in phpunit.xml so the suite never needs a live database.
*/

pest()->extend(TestCase::class)->in('Unit');

pest()->extend(TestCase::class)
    ->use(RefreshDatabase::class)
    ->in('Feature');
