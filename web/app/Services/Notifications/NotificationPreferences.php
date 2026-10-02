<?php

namespace App\Services\Notifications;

use App\Enums\NotificationType;
use App\Models\NotificationPreference;
use App\Models\User;

/**
 * Who wants which notifications, and how.
 *
 * By default everyone receives everything they are eligible for, in the app and
 * by email, and the digest comes weekly. A row is stored only when someone
 * changes a choice. A digest set to "off" is not sent at all.
 */
class NotificationPreferences
{
    public const DIGEST_FREQUENCIES = ['daily', 'weekly', 'off'];

    public const DEFAULT_DIGEST = 'weekly';

    /**
     * The person's settings for every kind of notification they could receive.
     *
     * @return array<string, array{mail: bool, database: bool, digest: string|null}> Keyed by type
     */
    public function for(User $user): array
    {
        $saved = NotificationPreference::query()->where('user_id', $user->id)->get()->keyBy('type');
        $settings = [];

        foreach (NotificationType::cases() as $type) {
            if (! $type->eligible($user)) {
                continue;
            }

            $row = $saved->get($type->value);

            $settings[$type->value] = [
                'mail' => $row->mail ?? true,
                'database' => $row->database ?? true,
                'digest' => $type->isDigest() ? ($row->digest_frequency ?? self::DEFAULT_DIGEST) : null,
            ];
        }

        return $settings;
    }

    /**
     * The channels a notification of this type should go out on for this person:
     * a subset of `mail` and `database`, empty if they have turned it off or are
     * not eligible.
     *
     * @return list<string>
     */
    public function channels(User $user, NotificationType $type): array
    {
        $setting = $this->for($user)[$type->value] ?? null;

        if ($setting === null || ($type->isDigest() && $setting['digest'] === 'off')) {
            return [];
        }

        return array_values(array_filter([
            $setting['mail'] ? 'mail' : null,
            $setting['database'] ? 'database' : null,
        ]));
    }

    /**
     * How often this person wants the digest ('daily', 'weekly' or 'off'); null if they could not receive it.
     */
    public function digestFrequency(User $user): ?string
    {
        return $this->for($user)[NotificationType::Digest->value]['digest'] ?? null;
    }

    /**
     * Saves the person's choices. Kinds they could not receive are ignored.
     *
     * @param  array<string, array{mail?: bool, database?: bool, digest?: string|null}>  $input  Keyed by type
     */
    public function update(User $user, array $input): void
    {
        foreach (NotificationType::cases() as $type) {
            if (! $type->eligible($user) || ! isset($input[$type->value])) {
                continue;
            }

            $choice = $input[$type->value];

            NotificationPreference::query()->updateOrCreate(
                ['user_id' => $user->id, 'type' => $type->value],
                [
                    'mail' => (bool) ($choice['mail'] ?? true),
                    'database' => (bool) ($choice['database'] ?? true),
                    'digest_frequency' => $type->isDigest() ? ($choice['digest'] ?? self::DEFAULT_DIGEST) : null,
                ],
            );
        }
    }
}
