<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Inertia\Inertia;
use Symfony\Component\HttpFoundation\Response;

/**
 * Ends the session of a user who has been deactivated while signed in.
 * (Deactivated users are also refused at login; see FortifyServiceProvider.)
 */
class EnsureUserIsActive
{
    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();

        if ($user !== null && ! $user->is_active) {
            Auth::logout();

            $request->session()->invalidate();
            $request->session()->regenerateToken();

            Inertia::flash('toast', [
                'type' => 'error',
                'message' => 'Your account has been deactivated. Contact an Owner.',
            ]);

            return redirect()->route('login');
        }

        return $next($request);
    }
}
