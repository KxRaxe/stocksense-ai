<?php

use App\Models\User;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Contracts\View\View;
use Illuminate\Routing\Route as RouteDefinition;
use Illuminate\Support\Facades\Route;

/*
 * A page's own props replace shared props of the same name. That once took the
 * whole notifications page down: its list was called `notifications`, like the
 * bell's data, and the bell crashed on it. Every page an Owner can open must
 * still carry every shared prop, so a clash shows up here.
 *
 * Add a shared prop to HandleInertiaRequests, add it here.
 */
const SHARED_PROPS = ['name', 'auth', 'bell', 'sidebarOpen'];

it('keeps the shared props on every page that needs no parameters', function () {
    $this->seed(RolesAndPermissionsSeeder::class);
    $owner = User::factory()->owner()->create();

    $uris = collect(Route::getRoutes()->getRoutes())
        ->filter(fn (RouteDefinition $route) => in_array('GET', $route->methods(), true)
            && ! str_contains($route->uri(), '{')
            && in_array('auth', $route->gatherMiddleware(), true))
        ->map(fn (RouteDefinition $route) => '/'.ltrim($route->uri(), '/'))
        ->unique()
        ->values();

    $checked = [];
    $missing = [];

    foreach ($uris as $uri) {
        $response = $this->actingAs($owner)->get($uri);
        $view = $response->baseResponse->original ?? null;

        if (! $response->isOk() || ! $view instanceof View || ! is_array($view->getData()['page'] ?? null)) {
            continue;   // a download, a redirect or a page that is not Inertia's
        }

        /** @var array{component: string, props: array<string, mixed>} $page */
        $page = $view->getData()['page'];

        $lost = array_values(array_diff(SHARED_PROPS, array_keys($page['props'])));

        if ($lost !== []) {
            $missing["{$page['component']} ({$uri})"] = $lost;
        }

        // The bell's data is real, not merely present: it is the person's own.
        if (isset($page['props']['bell'])) {
            expect($page['props']['bell'])->toHaveKeys(['unread', 'recent']);
        }

        $checked[] = $page['component'];
    }

    expect($missing)->toBe([]);

    // Guards against passing by checking nothing, if the way pages are rendered changes.
    expect($checked)->toContain('dashboard', 'notifications/index', 'recommendations/index', 'settings/notifications')
        ->and(count($checked))->toBeGreaterThan(10);
});
