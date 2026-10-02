<?php

use App\Enums\Role;
use App\Models\User;
use App\Notifications\SetUpAccountNotification;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Support\Facades\Notification;

beforeEach(fn () => $this->seed(RolesAndPermissionsSeeder::class));

it('creates a verified owner and emails a set-up link', function () {
    Notification::fake();

    $this->artisan('app:create-owner', ['name' => 'First Owner', 'email' => 'First.Owner@Example.test'])
        ->expectsOutputToContain('set-up email has been sent')
        ->assertSuccessful();

    $owner = User::where('email', 'first.owner@example.test')->firstOrFail();

    expect($owner->currentRole())->toBe(Role::Owner)
        ->and($owner->is_active)->toBeTrue()
        ->and($owner->email_verified_at)->not->toBeNull();

    Notification::assertSentTo($owner, SetUpAccountNotification::class);
});

it('can print the link instead of emailing it', function () {
    Notification::fake();

    $this->artisan('app:create-owner', ['name' => 'First Owner', 'email' => 'first.owner@example.test', '--print-link' => true])
        ->expectsOutputToContain('/reset-password/')
        ->assertSuccessful();

    Notification::assertNothingSent();
});

it('refuses an email address that is already in use', function () {
    User::factory()->create(['email' => 'taken@example.test']);

    $this->artisan('app:create-owner', ['name' => 'Someone', 'email' => 'taken@example.test'])
        ->expectsOutputToContain('already exists')
        ->assertFailed();

    expect(User::count())->toBe(1);
});

it('refuses an invalid email address', function () {
    $this->artisan('app:create-owner', ['name' => 'Someone', 'email' => 'not-an-email'])
        ->expectsOutputToContain('not a valid email')
        ->assertFailed();

    expect(User::count())->toBe(0);
});
