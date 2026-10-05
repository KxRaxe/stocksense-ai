<?php

use App\Models\Category;
use App\Models\Product;
use App\Models\User;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\RateLimiter;

beforeEach(function () {
    $this->seed(RolesAndPermissionsSeeder::class);
    RateLimiter::clear('exports');

    $this->owner = User::factory()->owner()->create();
    $this->manager = User::factory()->manager()->create();
});

describe('downloads', function () {
    it('are limited to ten a minute, then ask the person to wait', function () {
        $this->actingAs($this->owner);

        foreach (range(1, 10) as $_) {
            $this->get(route('reports.export', ['sales', 'xlsx']))->assertOk();
        }

        $response = $this->get(route('reports.export', ['sales', 'xlsx']));

        $response->assertTooManyRequests();

        expect($response->headers->get('Retry-After'))->not->toBeNull();
    });

    it('count Excel and PDF together, and views not at all', function () {
        $this->actingAs($this->owner);

        foreach (range(1, 20) as $_) {
            $this->get(route('reports.show', 'sales'))->assertOk();
        }

        foreach (range(1, 5) as $_) {
            $this->get(route('reports.export', ['sales', 'xlsx']))->assertOk();
            $this->get(route('reports.export', ['inventory', 'pdf']))->assertOk();
        }

        $this->get(route('reports.export', ['sales', 'pdf']))->assertTooManyRequests();
    });

    it('are limited for each person, not for everyone together', function () {
        foreach (range(1, 10) as $_) {
            $this->actingAs($this->owner)->get(route('reports.export', ['sales', 'xlsx']))->assertOk();
        }

        $this->actingAs($this->owner)->get(route('reports.export', ['sales', 'xlsx']))->assertTooManyRequests();
        $this->actingAs($this->manager)->get(route('reports.export', ['sales', 'xlsx']))->assertOk();
    });

    it('follow the limit in the configuration', function () {
        config(['security.limits.exports' => 2]);

        $this->actingAs($this->owner);
        $this->get(route('reports.export', ['sales', 'xlsx']))->assertOk();
        $this->get(route('reports.export', ['sales', 'xlsx']))->assertOk();
        $this->get(route('reports.export', ['sales', 'xlsx']))->assertTooManyRequests();
    });
});

describe('actions that start work', function () {
    it('limit how often a forecast can be started', function () {
        Queue::fake();
        $this->actingAs($this->owner);

        foreach (range(1, 6) as $_) {
            $this->post(route('forecasts.run'), ['granularity' => 'week'])->assertSessionHasNoErrors();
        }

        $this->post(route('forecasts.run'), ['granularity' => 'week'])->assertTooManyRequests();
    });

    it('limit how often the advice can be recalculated', function () {
        Queue::fake();
        $this->actingAs($this->owner);

        foreach (range(1, 6) as $_) {
            $this->post(route('recommendations.refresh'))->assertRedirect();
        }

        $this->post(route('recommendations.refresh'))->assertTooManyRequests();
    });

    it('limit how often a set-up email can be sent', function () {
        $target = User::factory()->inventoryStaff()->create();
        $this->actingAs($this->owner);

        foreach (range(1, 6) as $_) {
            $this->post(route('users.setup-link', $target))->assertRedirect();
        }

        $this->post(route('users.setup-link', $target))->assertTooManyRequests();
    });

    it('share one budget for everything that starts work, so changing the action does not reset it', function () {
        Queue::fake();
        $this->actingAs($this->owner);

        foreach (range(1, 3) as $_) {
            $this->post(route('forecasts.run'), ['granularity' => 'week']);
            $this->post(route('recommendations.refresh'));
        }

        $this->post(route('recommendations.refresh'))->assertTooManyRequests();
    });

    it('do not get in the way of the everyday decisions people make', function () {
        $category = Category::factory()->create();
        $product = Product::factory()->for($category)->create();
        $this->actingAs($this->owner);

        foreach (range(1, 12) as $_) {
            $this->post(route('products.restock', $product), ['quantity' => 1])->assertSessionHasNoErrors();
        }
    });
});

describe('signing in', function () {
    it('is limited to five tries a minute for each address', function () {
        $user = User::factory()->create(['email' => 'someone@example.test']);

        foreach (range(1, 5) as $_) {
            $this->post(route('login.store'), ['email' => $user->email, 'password' => 'wrong'])->assertSessionHasErrors('email');
        }

        $this->post(route('login.store'), ['email' => $user->email, 'password' => 'password'])->assertTooManyRequests();
    });

    it('is limited for each address separately, so one person\'s mistakes do not lock out another', function () {
        User::factory()->create(['email' => 'one@example.test']);
        $other = User::factory()->create(['email' => 'two@example.test']);

        foreach (range(1, 6) as $_) {
            $this->post(route('login.store'), ['email' => 'one@example.test', 'password' => 'wrong']);
        }

        $this->post(route('login.store'), ['email' => $other->email, 'password' => 'password'])->assertRedirect();
    });
});

describe('what the person sees when they are slowed down', function () {
    it('is a message on the login form, for someone signing in from the app', function () {
        $user = User::factory()->create(['email' => 'slow.down@example.test']);

        foreach (range(1, 5) as $_) {
            $this->withHeaders(['X-Inertia' => 'true'])->post(route('login.store'), ['email' => $user->email, 'password' => 'wrong']);
        }

        $response = $this->withHeaders(['X-Inertia' => 'true'])->post(route('login.store'), ['email' => $user->email, 'password' => 'password']);

        $response->assertRedirect()->assertSessionHasErrors('email');

        expect(session('errors')->first('email'))->toStartWith('Too many login attempts. Please try again in ')->toEndWith(' seconds.');

        expect(auth()->check())->toBeFalse();
    });

    it('is a message in the page, for an action started from the app', function () {
        Queue::fake();
        config(['security.limits.heavy' => 1]);

        $this->actingAs($this->owner);
        $this->withHeaders(['X-Inertia' => 'true'])->post(route('recommendations.refresh'))->assertRedirect();

        $this->withHeaders(['X-Inertia' => 'true'])->from('/recommendations')->post(route('recommendations.refresh'))
            ->assertRedirect('/recommendations')
            ->assertSessionHas('inertia.flash_data.toast', fn ($toast) => $toast['type'] === 'error'
                && str_starts_with($toast['message'], 'You are doing that too often. Please wait ')
                && str_ends_with($toast['message'], ' seconds and try again.'));
    });

    it('is still the ordinary "too many requests" page for a download', function () {
        config(['security.limits.exports' => 1]);

        $this->actingAs($this->owner);
        $this->get(route('reports.export', ['sales', 'xlsx']))->assertOk();

        $this->get(route('reports.export', ['sales', 'xlsx']))->assertTooManyRequests();
    });
});
