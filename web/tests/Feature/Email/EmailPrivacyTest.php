<?php

use App\Enums\ForecastGranularity;
use App\Enums\ImportType;
use App\Models\User;
use App\Notifications\CriticalStockNotification;
use App\Notifications\ForecastRunNotification;
use App\Notifications\ImportErrorsNotification;
use App\Notifications\ReplenishmentDigestNotification;
use App\Notifications\SetUpAccountNotification;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Auth\Notifications\ResetPassword;
use Illuminate\Auth\Notifications\VerifyEmail;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;
use Illuminate\Support\Facades\File;
use Symfony\Component\Mime\Email;

/*
 * Email policy: no email the application sends may contain personal data.
 * Each notification is rendered for a user with a distinctive name and email
 * address, then every part of the message is searched for them.
 *
 * Add every new notification that has a mail channel to this dataset.
 */
/**
 * One of each email the application can send, by name.
 *
 * @return array<string, array{0: Closure(): Notification}>
 */
function mailNotifications(): array
{
    return [
        'set up account' => [fn () => new SetUpAccountNotification('one-time-token')],
        'reset password' => [fn () => new ResetPassword('one-time-token')],
        'verify email' => [fn () => new VerifyEmail],
        'critical stock' => [fn () => new CriticalStockNotification([
            ['name' => 'Nails', 'sku' => 'HW-1', 'available' => 10, 'expected' => 70],
            ['name' => 'Cement', 'sku' => 'HW-2', 'available' => 0, 'expected' => 40.5],
        ])],
        'replenishment digest' => [fn () => new ReplenishmentDigestNotification(
            ['critical' => 1, 'low' => 2, 'watch' => 3, 'overstock' => 4],
            [['name' => 'Nails', 'sku' => 'HW-1', 'risk' => 'Critical', 'quantity' => 130, 'when' => 'now']],
            30,
        )],
        'forecast ready' => [fn () => new ForecastRunNotification(ForecastGranularity::Week, 8, null, ['model' => 16.3, 'seasonal_naive' => 19.7])],
        'forecast failed' => [fn () => new ForecastRunNotification(ForecastGranularity::Month, 3, 'The forecasting service could not be reached.')],
        'import with problems' => [fn () => new ImportErrorsNotification(12, ImportType::Sales, 10, 2, false, '/sales/imports/12')],
        'import stopped' => [fn () => new ImportErrorsNotification(13, ImportType::Products, 4, 0, true, '/products/imports/13')],
    ];
}

dataset('mail notifications', mailNotifications());

it('covers every notification in the application that can send mail', function () {
    $covered = collect(mailNotifications())->map(fn (array $case) => get_class($case[0]()))->unique();

    $sendsMail = collect(File::files(app_path('Notifications')))
        ->map(fn (SplFileInfo $file) => 'App\\Notifications\\'.$file->getFilenameWithoutExtension())
        ->reject(fn (string $class) => (new ReflectionClass($class))->isAbstract())
        ->filter(fn (string $class) => method_exists($class, 'toMail'))
        ->values();

    expect($sendsMail)->not->toBeEmpty()
        ->and($sendsMail->diff($covered)->values()->all())->toBe([]);
});

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

/**
 * Sends the notification for real (to the test mailer, which keeps what it is given)
 * and returns every part of the email that was actually produced.
 *
 * @return list<string>
 */
function sentMailParts(User $user, Notification $notification): array
{
    $transport = app('mail.manager')->mailer('array')->getSymfonyTransport();
    $before = count($transport->messages());

    $user->notify($notification);

    $parts = [];

    foreach (array_slice($transport->messages()->all(), $before) as $sent) {
        /** @var Email $email */
        $email = $sent->getOriginalMessage();
        $parts[] = (string) $email->getSubject();
        $parts[] = (string) $email->getHtmlBody();
        $parts[] = (string) $email->getTextBody();
    }

    return $parts;
}

it('contains no personal data in the email as actually rendered and sent', function (Closure $makeNotification) {
    $this->seed(RolesAndPermissionsSeeder::class);
    $user = User::factory()->owner()->create([
        'name' => 'Zebediah Quillfeather',
        'email' => 'zebediah.quillfeather@example.test',
    ]);

    $parts = sentMailParts($user, $makeNotification());

    expect($parts)->not->toBeEmpty();

    $haystack = strtolower(implode("\n", $parts));

    foreach (['Zebediah', 'Quillfeather', 'zebediah.quillfeather', 'example.test', urlencode($user->email)] as $personalData) {
        expect($haystack)->not->toContain(strtolower($personalData));
    }

    // It opens with a generic greeting ("Hello," or the framework's "Hello!"), not the person's name.
    expect($haystack)->toMatch('/hello[,!]/');
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

it('is drawn in the StockSense theme', function () {
    $this->seed(RolesAndPermissionsSeeder::class);
    $user = User::factory()->owner()->create();

    $html = implode("\n", sentMailParts($user, new SetUpAccountNotification('one-time-token')));

    // The theme's violet button and ink card edge, inlined into the email.
    expect($html)->toContain('#6639ee')->toContain('#121524');
});
