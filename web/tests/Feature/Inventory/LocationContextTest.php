<?php

use App\Models\Location;
use App\Services\Inventory\LocationContext;
use Illuminate\Database\QueryException;

it('has exactly one location, the default, straight after migrating', function () {
    expect(Location::count())->toBe(1)
        ->and(Location::defaultLocation()->is_default)->toBeTrue();
});

it('allows only one default location', function () {
    expect(fn () => Location::create(['name' => 'Another default', 'code' => 'TWO', 'is_default' => true]))
        ->toThrow(QueryException::class);
});

it('resolves to the default location', function () {
    $context = app(LocationContext::class);

    expect($context->current()->is(Location::defaultLocation()))->toBeTrue()
        ->and($context->id())->toBe(Location::defaultLocation()->id);
});

it('stays on the default location even if the multi-location flag is switched on', function () {
    config(['features.multi_location' => true]);

    expect(app(LocationContext::class)->current()->is_default)->toBeTrue();
});

it('is scoped, so each request or queued job starts fresh', function () {
    expect(app(LocationContext::class))->toBe(app(LocationContext::class));

    $before = app(LocationContext::class);
    app()->forgetScopedInstances();

    expect(app(LocationContext::class))->not->toBe($before);
});
