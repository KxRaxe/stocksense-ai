<?php

namespace App\Services\Notifications;

use Illuminate\Notifications\DatabaseNotification;

/**
 * A stored notification as the screen needs it.
 */
class NotificationPresenter
{
    /**
     * @return array{id: string, type: string, title: string, message: string, url: string, read: bool, created_at: string|null}
     */
    public function present(DatabaseNotification $notification): array
    {
        /** @var array{type?: string, title?: string, message?: string, url?: string} $data */
        $data = $notification->data;

        return [
            'id' => $notification->id,
            'type' => $data['type'] ?? '',
            'title' => $data['title'] ?? '',
            'message' => $data['message'] ?? '',
            'url' => self::safePath($data['url'] ?? '/'),
            'read' => $notification->read_at !== null,
            'created_at' => $notification->created_at?->toIso8601String(),
        ];
    }

    /**
     * A notification only ever leads somewhere inside the app: a path such as
     * "/recommendations", never another site.
     */
    public static function safePath(string $path): string
    {
        return str_starts_with($path, '/') && ! str_starts_with($path, '//') && ! str_contains($path, '\\') ? $path : '/';
    }
}
