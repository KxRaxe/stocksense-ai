<?php

namespace App\Http\Middleware;

use App\Services\Notifications\NotificationPresenter;
use Illuminate\Http\Request;
use Inertia\Middleware;

class HandleInertiaRequests extends Middleware
{
    /**
     * The root template that's loaded on the first page visit.
     *
     * @see https://inertiajs.com/server-side-setup#root-template
     *
     * @var string
     */
    protected $rootView = 'app';

    /**
     * Determines the current asset version.
     *
     * @see https://inertiajs.com/asset-versioning
     */
    public function version(Request $request): ?string
    {
        return parent::version($request);
    }

    /**
     * Define the props that are shared by default.
     *
     * @see https://inertiajs.com/shared-data
     *
     * @return array<string, mixed>
     */
    public function share(Request $request): array
    {
        return [
            ...parent::share($request),
            'name' => config('app.name'),
            'auth' => [
                'user' => $request->user(),
                // Used by the frontend to show or hide navigation and buttons.
                // The server still enforces every permission on its own.
                'role' => $request->user()?->currentRole()?->value,
                'permissions' => $request->user()?->getAllPermissions()->pluck('name')->values()->all() ?? [],
            ],
            // The bell: how many are unread and the latest few. Worked out for each page, which is
            // two small queries for someone signed in. Not called `notifications`: the notifications
            // page has a prop of that name, and a page's own props replace shared ones.
            'bell' => fn () => $request->user() === null ? null : [
                'unread' => $request->user()->unreadNotifications()->count(),
                'recent' => $request->user()->notifications()->limit(8)->get()
                    ->map(fn ($notification) => app(NotificationPresenter::class)->present($notification))
                    ->all(),
            ],
            'sidebarOpen' => ! $request->hasCookie('sidebar_state') || $request->cookie('sidebar_state') === 'true',
        ];
    }
}
