<?php

namespace App\Notifications;

use App\Enums\NotificationType;
use App\Models\User;
use App\Services\Notifications\NotificationPreferences;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/**
 * What every notification the app sends has in common.
 *
 * Channels come from the person's own settings, so a notification they have
 * turned off is not sent at all. Each one is sent through the queue.
 *
 * Email policy: a message never contains a name or an email address. It opens
 * with the generic greeting "Hello," and says only what happened, in terms of
 * products, quantities and dates, with a link back to the app. Subclasses supply
 * the words; this class builds the message, so the rule is kept in one place.
 * (File names are left out as well, as people name files after people.)
 * EmailPrivacyTest renders every notification and searches for personal data.
 */
abstract class StockSenseNotification extends Notification implements ShouldQueue
{
    use Queueable;

    abstract public static function type(): NotificationType;

    /**
     * The headline: the email's subject and the bell's title.
     */
    abstract protected function title(): string;

    /**
     * One short sentence for the bell.
     */
    abstract protected function summary(): string;

    /**
     * Where the notification leads, as a path inside the app.
     */
    abstract protected function path(): string;

    /**
     * The email's body, one paragraph per entry.
     *
     * @return list<string>
     */
    abstract protected function lines(): array;

    abstract protected function actionText(): string;

    /**
     * @return list<string>
     */
    public function via(object $notifiable): array
    {
        /** @var User $notifiable */
        return app(NotificationPreferences::class)->channels($notifiable, static::type());
    }

    public function toMail(object $notifiable): MailMessage
    {
        $mail = (new MailMessage)->subject($this->title())->greeting('Hello,');

        foreach ($this->lines() as $line) {
            $mail->line($line);
        }

        return $mail
            ->action($this->actionText(), url($this->path()))
            ->line('You can choose which notifications you receive under Settings, then Notifications.');
    }

    /**
     * What the bell shows and stores.
     *
     * @return array{type: string, title: string, message: string, url: string}
     */
    public function toArray(object $notifiable): array
    {
        return [
            'type' => static::type()->value,
            'title' => $this->title(),
            'message' => $this->summary(),
            'url' => $this->path(),
        ];
    }
}
