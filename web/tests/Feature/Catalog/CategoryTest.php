<?php

use App\Models\Category;
use App\Models\Product;
use App\Models\User;
use Database\Seeders\RolesAndPermissionsSeeder;
use Spatie\Activitylog\Models\Activity;

beforeEach(function () {
    $this->seed(RolesAndPermissionsSeeder::class);

    $this->manager = User::factory()->manager()->create();
});

it('lists categories with how many products each has', function () {
    $category = Category::factory()->create(['name' => 'Hardware']);
    Product::factory()->count(3)->for($category)->create();

    $this->actingAs($this->manager)
        ->get(route('categories.index'))
        ->assertInertia(fn ($page) => $page
            ->component('categories/index')
            ->where('categories.0.name', 'Hardware')
            ->where('categories.0.products_count', 3)
            ->where('can.manage', true));
});

it('tells staff they cannot manage categories', function () {
    $this->actingAs(User::factory()->inventoryStaff()->create())
        ->get(route('categories.index'))
        ->assertInertia(fn ($page) => $page->where('can.manage', false));
});

describe('creating', function () {
    it('saves a category and logs who made it', function () {
        $this->actingAs($this->manager)
            ->post(route('categories.store'), [
                'name' => 'Food and beverages',
                'description' => 'Fast-moving groceries',
                'service_level' => '97.5',
            ])
            ->assertRedirect(route('categories.index'));

        $category = Category::firstWhere('name', 'Food and beverages');

        expect($category->service_level)->toBe('97.50');

        $activity = Activity::where('log_name', 'catalog')->where('event', 'created')->firstOrFail();
        expect($activity->causer_id)->toBe($this->manager->id);
    });

    it('rejects a name that is already used, ignoring capitals', function () {
        Category::factory()->create(['name' => 'Hardware']);

        $this->actingAs($this->manager)
            ->post(route('categories.store'), ['name' => '  hardware ', 'service_level' => 95])
            ->assertSessionHasErrors('name');

        expect(Category::count())->toBe(1);
    });

    it('validates the service level', function (mixed $level) {
        $this->actingAs($this->manager)
            ->post(route('categories.store'), ['name' => 'Anything', 'service_level' => $level])
            ->assertSessionHasErrors('service_level');
    })->with(['too low' => [49], 'too high' => [100], 'not a number' => ['high'], 'missing' => [null]]);
});

describe('editing', function () {
    it('updates a category without clashing with itself', function () {
        $category = Category::factory()->create(['name' => 'Hardware', 'service_level' => 90]);

        $this->actingAs($this->manager)
            ->put(route('categories.update', $category), [
                'name' => 'Hardware',
                'description' => 'Tools and materials',
                'service_level' => 92,
            ])
            ->assertRedirect(route('categories.index'))
            ->assertSessionHasNoErrors();

        expect($category->fresh()->description)->toBe('Tools and materials')
            ->and($category->fresh()->service_level)->toBe('92.00');
    });

    it('cannot be renamed to another category\'s name', function () {
        Category::factory()->create(['name' => 'Hardware']);
        $other = Category::factory()->create(['name' => 'Toys']);

        $this->actingAs($this->manager)
            ->put(route('categories.update', $other), ['name' => 'HARDWARE', 'service_level' => 95])
            ->assertSessionHasErrors('name');
    });
});

describe('deleting', function () {
    it('removes an empty category', function () {
        $category = Category::factory()->create();

        $this->actingAs($this->manager)
            ->delete(route('categories.destroy', $category))
            ->assertRedirect(route('categories.index'));

        expect(Category::count())->toBe(0);
    });

    it('refuses while products still use it, even archived ones', function () {
        $category = Category::factory()->create();
        Product::factory()->archived()->for($category)->create();

        $this->actingAs($this->manager)
            ->delete(route('categories.destroy', $category))
            ->assertSessionHasErrors('category');

        expect($category->fresh())->not->toBeNull();
    });
});
