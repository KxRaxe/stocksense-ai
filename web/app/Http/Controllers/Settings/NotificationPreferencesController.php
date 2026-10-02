<?php

namespace App\Http\Controllers\Settings;

use App\Enums\NotificationType;
use App\Http\Controllers\Controller;
use App\Services\Notifications\NotificationPreferences;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Which notifications a person gets, and how: in the app, by email, or both,
 * and how often the digest comes. Only the kinds they could receive are shown.
 */
class NotificationPreferencesController extends Controller
{
    public function __construct(private readonly NotificationPreferences $preferences) {}

    public function edit(Request $request): Response
    {
        $user = $request->user();
        $settings = $this->preferences->for($user);

        return Inertia::render('settings/notifications', [
            'types' => array_values(array_map(
                fn (NotificationType $type) => [
                    'type' => $type->value,
                    'label' => $type->label(),
                    'description' => $type->description(),
                    'is_digest' => $type->isDigest(),
                    ...$settings[$type->value],
                ],
                array_filter(NotificationType::cases(), fn (NotificationType $type) => isset($settings[$type->value])),
            )),
            'frequencies' => [
                ['value' => 'daily', 'label' => 'Every day'],
                ['value' => 'weekly', 'label' => 'Every Monday'],
                ['value' => 'off', 'label' => 'Never'],
            ],
        ]);
    }

    public function update(Request $request): RedirectResponse
    {
        $request->validate([
            'settings' => ['required', 'array'],
            'settings.*.mail' => ['required', 'boolean'],
            'settings.*.database' => ['required', 'boolean'],
            'settings.*.digest' => ['nullable', Rule::in(NotificationPreferences::DIGEST_FREQUENCIES)],
        ]);

        /** @var array<string, array{mail?: bool, database?: bool, digest?: string|null}> $input */
        $input = $request->input('settings');

        $this->preferences->update($request->user(), $input);

        Inertia::flash('toast', ['type' => 'success', 'message' => 'Notification settings saved.']);

        return back();
    }
}
