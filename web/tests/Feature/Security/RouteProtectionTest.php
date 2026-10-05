<?php

use Illuminate\Routing\Route as RouteDefinition;
use Illuminate\Support\Facades\Route;

/*
 * A new route is protected unless someone decides otherwise. Every route that does not
 * require a signed-in user must be on this list, on purpose; anything else fails here,
 * so a forgotten `auth` shows up in review rather than in production.
 */
const PUBLIC_ROUTES = [
    'home',                    // the public front page (no shop data); signed in, it redirects to the dashboard
    'login',
    'login.store',
    'password.request',
    'password.email',
    'password.reset',
    'password.update',
    'two-factor.login',
    'two-factor.login.store',
];

/**
 * @return list<RouteDefinition>
 */
function allRoutes(): array
{
    return Route::getRoutes()->getRoutes();
}

function requiresLogin(RouteDefinition $route): bool
{
    return collect($route->gatherMiddleware())->contains(
        fn ($middleware) => is_string($middleware) && ($middleware === 'auth' || str_starts_with($middleware, 'auth:') || str_contains($middleware, 'Authenticate')),
    );
}

it('requires a signed-in user for every route except the few that cannot', function () {
    $open = collect(allRoutes())
        ->reject(fn (RouteDefinition $route) => requiresLogin($route))
        ->reject(fn (RouteDefinition $route) => in_array($route->getName(), PUBLIC_ROUTES, true))
        ->reject(fn (RouteDefinition $route) => $route->uri() === 'up')    // the health check, which says nothing
        ->map(fn (RouteDefinition $route) => implode('|', $route->methods()).' '.$route->uri())
        ->values()
        ->all();

    expect($open)->toBe([]);
});

it('names a public route that exists, so the list does not collect dead entries', function () {
    $names = collect(allRoutes())->map(fn (RouteDefinition $route) => $route->getName())->filter()->all();

    expect(array_diff(PUBLIC_ROUTES, $names))->toBe([]);
});

it('has no route that serves files from storage by link', function () {
    $uris = collect(allRoutes())->map(fn (RouteDefinition $route) => $route->uri());

    expect($uris->filter(fn (string $uri) => str_starts_with($uri, 'storage/'))->all())->toBe([]);
});

it('has no developer tooling routes outside local development', function () {
    $uris = collect(allRoutes())->map(fn (RouteDefinition $route) => $route->uri());

    expect($uris->filter(fn (string $uri) => str_starts_with($uri, '_inertia') || str_starts_with($uri, 'telescope') || str_starts_with($uri, '_ignition'))->all())->toBe([]);
});

it('asks for a permission, not just a login, on every page that shows shop data', function () {
    // Pages open to any signed-in person on purpose: their own account, notifications, and
    // the dashboard, which decides for itself what to show. Everything else needs a permission.
    $anyone = ['dashboard', 'notifications.', 'verification.', 'profile.', 'user-password.', 'security.', 'two-factor.', 'appearance.', 'notification-preferences.', 'password.confirm', 'password.confirmation', 'logout', 'verification', 'reports.index', 'reports.show', 'reports.export', 'passkey'];

    $unguarded = collect(allRoutes())
        ->filter(fn (RouteDefinition $route) => requiresLogin($route) && $route->getName() !== null)
        ->reject(fn (RouteDefinition $route) => collect($anyone)->contains(fn (string $prefix) => str_starts_with((string) $route->getName(), $prefix)))
        ->reject(fn (RouteDefinition $route) => collect($route->gatherMiddleware())->contains(fn ($m) => is_string($m) && str_starts_with($m, 'can:')))
        ->reject(fn (RouteDefinition $route) => str_starts_with($route->uri(), 'horizon') || str_starts_with($route->uri(), 'user/') || str_starts_with($route->uri(), 'settings/'))
        ->map(fn (RouteDefinition $route) => implode('|', $route->methods()).' '.$route->uri().' ['.$route->getName().']')
        ->values()
        ->all();

    expect($unguarded)->toBe([]);
});
