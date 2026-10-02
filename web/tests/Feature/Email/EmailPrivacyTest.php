<?php

use App\Models\User;
use App\Notifications\SetUpAccountNotification;
use Illuminate\Auth\Notifications\ResetPassword;
use Illuminate\Auth\Notifications\VerifyEmail;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/*
 * Email policy: no email the application sends may contain personal data.
 * Each notification is rendered for a user with a distinctive name and email
 * address, then every part of the message is searched for them.
 *
 * Add every new notification that has a mail channel to this dataset.
 */
dataset('mail notifications', [
    'set up account' => [fn () => new SetUpAccountNotification('one-time-token')],
    'reset password' => [fn () => new ResetPassword('one-time-token')],
    'verify email' => [fn () => new VerifyEmail],
]);

/**
 * @return list<string> every searchable part of a mail message
 */
function mailParts(MailMessage $mail): array
{
    return [
        (string) $mail->subject,
        (string) $mail->greeting,
        (string) $mail->salutation,
        (string) $mail->actionUrl,
        (string) $mail->actionText,
        ...array_map('strval', $mail->introLines),
        ...array_map('strval', $mail->outroLines),
        (string) $mail->render(),
    ];
}

it('contains no personal data', function (Closure $makeNotification) {
    $user = User::factory()->create([
        'name' => 'Zebediah Quillfeather',
        'email' => 'zebediah.quillfeather@example.test',
    ]);

    /** @var Notification $notification */
    $notification = $makeNotification();

    $haystack = strtolower(implode("\n", mailParts($notification->toMail($user))));

    foreach ([
        $user->name,
        'Zebediah',
        'Quillfeather',
        $user->email,
        urlencode($user->email),
        rawurlencode($user->email),
        'zebediah.quillfeather',
    ] as $personalData) {
        expect($haystack)->not->toContain(strtolower($personalData));
    }
})->with('mail notifications');

it('builds password links from the token alone', function () {
    $user = User::factory()->create();

    $url = (new SetUpAccountNotification('one-time-token'))->toMail($user)->actionUrl;

    expect($url)->toContain('/reset-password/one-time-token')->not->toContain('email=')->not->toContain('?');
});

it('lets the person type their email on the reset page', function () {
    $this->get(route('password.reset', ['token' => 'one-time-token']))
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->component('auth/reset-password')
            ->where('token', 'one-time-token')
            ->where('email', null));
});
