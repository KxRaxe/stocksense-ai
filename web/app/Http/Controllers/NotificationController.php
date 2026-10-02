<?php

namespace App\Http\Controllers;

use App\Services\Notifications\NotificationPresenter;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/**
 * A person's own notifications: the full list behind the bell, and marking them
 * read. Everyone has them; nobody can reach anyone else's.
 */
class NotificationController extends Controller
{
    public function __construct(private readonly NotificationPresenter $presenter) {}

    public function index(Request $request): Response
    {
        return Inertia::render('notifications/index', [
            'notifications' => $request->user()->notifications()
                ->paginate(20)
                ->through(fn ($notification) => $this->presenter->present($notification)),
        ]);
    }

    /**
     * Marks one read and goes where it points. A plain link in the bell, so it works without scripts.
     */
    public function open(Request $request, string $notification): RedirectResponse
    {
        $found = $request->user()->notifications()->findOrFail($notification);
        $found->markAsRead();

        return redirect($this->presenter->present($found)['url']);
    }

    public function read(Request $request, string $notification): RedirectResponse
    {
        $request->user()->notifications()->findOrFail($notification)->markAsRead();

        return back();
    }

    public function readAll(Request $request): RedirectResponse
    {
        $request->user()->unreadNotifications()->update(['read_at' => now()]);

        return back();
    }
}
