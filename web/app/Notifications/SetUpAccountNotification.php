<?php

namespace App\Notifications;

use Illuminate\Auth\Notifications\ResetPassword;
use Illuminate\Notifications\Messages\MailMessage;

/**
 * Sent when an Owner creates an account or asks for a new password link.
 *
 * Email policy: the message never contains a person's name or email address.
 * It uses the generic greeting, and the link carries only the one-time token
 * (see AppServiceProvider, which strips the email from reset URLs). The token
 * is a secret, so this notification is sent immediately rather than queued.
 */
class SetUpAccountNotification extends ResetPassword
{
    public function toMail($notifiable): MailMessage
    {
        $app = config('app.name');
        $minutes = config('auth.passwords.'.config('auth.defaults.passwords').'.expire');

        return (new MailMessage)
            ->subject("Set up your {$app} account")
            ->line("An administrator has asked you to choose a password for your {$app} account.")
            ->action('Choose a password', $this->resetUrl($notifiable))
            ->line("This link expires in {$minutes} minutes. If you were not expecting this message, you can ignore it.");
    }
}
