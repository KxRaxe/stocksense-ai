<?php

use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/*
|--------------------------------------------------------------------------
| Test case
|--------------------------------------------------------------------------
|
| Function-style (Pest) tests in tests/Feature get the application and a
| refreshed PostgreSQL test database. The starter kit's class-based tests
| keep working unchanged.
|
*/

pest()->extend(TestCase::class)
    ->use(RefreshDatabase::class)
    ->in('Feature');
