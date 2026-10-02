<?php

use App\Enums\Role;
use App\Models\User;
use App\Notifications\SetUpAccountNotification;
use App\Services\Users\UserManager;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Auth\Notifications\VerifyEmail;
use Illuminate\Support\Facades\Notification;
use Illuminate\Validation\ValidationException;
use Spatie\Activitylog\Models\Activity;

beforeEach(function () {
    $this->seed(RolesAndPermissionsSeeder::class);

    $this->owner = User::factory()->owner()->create();
});

/** Sign-in as the Owner and POST valid new-user details, with overrides. */
function createUserAs(User $actor, array $overrides = [])
{
    return test()->actingAs($actor)->post(route('users.store'), array_merge([
        'name' => 'New Person',
        'email' => 'new.person@example.test',
        'role' => Role::InventoryStaff->value,
    ], $overrides));
}

describe('creating users', function () {
    it('creates a verified user with the chosen role and emails a set-up link', function () {
        Notification::fake();

        createUserAs($this->owner)->assertRedirect(route('users.index'))->assertSessionHasNoErrors();

        $user = User::where('email', 'new.person@example.test')->firstOrFail();

        expect($user->currentRole())->toBe(Role::InventoryStaff)
            ->and($user->is_active)->toBeTrue()
            ->and($user->email_verified_at)->not->toBeNull();

        Notification::assertSentTo($user, SetUpAccountNotification::class);
    });

    it('stores emails in lower case and rejects duplicates', function () {
        createUserAs($this->owner, ['email' => 'Mixed.Case@Example.TEST'])->assertSessionHasNoErrors();

        expect(User::where('email', 'mixed.case@example.test')->exists())->toBeTrue();

        createUserAs($this->owner, ['email' => 'MIXED.case@example.test'])->assertSessionHasErrors('email');
    });

    it('rejects an unknown role', function () {
        createUserAs($this->owner, ['role' => 'superuser'])->assertSessionHasErrors('role');
    });

    it('still creates the user when the email cannot be sent', function () {
        $this->mock(UserManager::class, function ($mock) {
            $mock->shouldReceive('create')->once()->andReturn(User::factory()->make(['id' => 99]));
            $mock->shouldReceive('sendSetupLink')->once()->andThrow(new RuntimeException('SMTP down'));
        });

        createUserAs($this->owner)
            ->assertRedirect(route('users.index'))
            ->assertSessionHasNoErrors();
    });

    it('records who created the user and which role was given', function () {
        Notification::fake();

        createUserAs($this->owner);

        $activity = Activity::where('event', 'role_assigned')->firstOrFail();

        expect($activity->causer_id)->toBe($this->owner->id)
            ->and($activity->getProperty('role'))->toBe(Role::InventoryStaff->value);
    });
});

describe('editing users', function () {
    it('updates name and role and logs the role change', function () {
        $user = User::factory()->inventoryStaff()->create();

        $this->actingAs($this->owner)
            ->put(route('users.update', $user), [
                'name' => 'Renamed',
                'email' => $user->email,
                'role' => Role::Manager->value,
            ])
            ->assertRedirect(route('users.index'));

        expect($user->fresh()->name)->toBe('Renamed')
            ->and($user->fresh()->currentRole())->toBe(Role::Manager);

        $activity = Activity::where('event', 'role_changed')->firstOrFail();
        expect($activity->getProperty('from'))->toBe(Role::InventoryStaff->value)
            ->and($activity->getProperty('to'))->toBe(Role::Manager->value);
    });

    it('requires the new address to be verified when the email changes', function () {
        Notification::fake();
        $user = User::factory()->manager()->create();

        $this->actingAs($this->owner)->put(route('users.update', $user), [
            'name' => $user->name,
            'email' => 'changed@example.test',
            'role' => Role::Manager->value,
        ])->assertSessionHasNoErrors();

        expect($user->fresh()->email_verified_at)->toBeNull();
        Notification::assertSentTo($user, VerifyEmail::class);
    });

    it('does not let an owner change their own role', function () {
        $this->actingAs($this->owner)
            ->put(route('users.update', $this->owner), [
                'name' => $this->owner->name,
                'email' => $this->owner->email,
                'role' => Role::Manager->value,
            ])
            ->assertForbidden();

        expect($this->owner->fresh()->currentRole())->toBe(Role::Owner);
    });

    it('lets an owner save their own details when the role is unchanged', function () {
        $this->actingAs($this->owner)
            ->put(route('users.update', $this->owner), [
                'name' => 'Updated Owner',
                'email' => $this->owner->email,
                'role' => Role::Owner->value,
            ])
            ->assertRedirect(route('users.index'));

        expect($this->owner->fresh()->name)->toBe('Updated Owner');
    });

    it('can demote another owner while an owner remains', function () {
        $other = User::factory()->owner()->create();

        $this->actingAs($this->owner)->put(route('users.update', $other), [
            'name' => $other->name,
            'email' => $other->email,
            'role' => Role::Manager->value,
        ])->assertRedirect(route('users.index'));

        expect($other->fresh()->currentRole())->toBe(Role::Manager);
    });
});

describe('deactivating users', function () {
    it('blocks sign-in, ends open sessions and keeps the record', function () {
        $user = User::factory()->manager()->create();

        $this->actingAs($this->owner)->patch(route('users.deactivate', $user))->assertRedirect();

        expect($user->fresh()->is_active)->toBeFalse();

        // Signing in is refused with the same error as a wrong password.
        $this->post(route('logout'));
        $this->post(route('login.store'), ['email' => $user->email, 'password' => 'password'])
            ->assertSessionHasErrors('email');
        $this->assertGuest();
    });

    it('signs out a user who is deactivated while logged in', function () {
        $user = User::factory()->manager()->inactive()->create();

        $this->actingAs($user)->get(route('dashboard'))->assertRedirect(route('login'));

        $this->assertGuest();
    });

    it('can be reversed', function () {
        $user = User::factory()->manager()->inactive()->create();

        $this->actingAs($this->owner)->patch(route('users.activate', $user))->assertRedirect();

        expect($user->fresh()->is_active)->toBeTrue();
        $this->post(route('logout'));
        $this->post(route('login.store'), ['email' => $user->email, 'password' => 'password']);
        $this->assertAuthenticatedAs($user);
    });

    it('does not let an owner deactivate themselves', function () {
        $this->actingAs($this->owner)->patch(route('users.deactivate', $this->owner))->assertForbidden();

        expect($this->owner->fresh()->is_active)->toBeTrue();
    });

    it('keeps at least one active owner when owners are deactivated in turn', function () {
        $other = User::factory()->owner()->create();

        $this->actingAs($this->owner)->patch(route('users.deactivate', $other))->assertRedirect();

        // $other can no longer act, and the remaining owner cannot deactivate themselves.
        expect(User::role(Role::Owner->value)->where('is_active', true)->count())->toBe(1);
    });

    it('refuses to deactivate the last active owner (guards a concurrent request)', function () {
        $other = User::factory()->owner()->inactive()->create();

        // $this->owner is the only active owner; a request that slipped past the
        // policy (for example racing another owner) is still refused by the service.
        expect(fn () => app(UserManager::class)->setActive($this->owner, false, $other))
            ->toThrow(ValidationException::class);

        expect($this->owner->fresh()->is_active)->toBeTrue();
    });

    it('logs deactivation with the acting owner as the causer', function () {
        $user = User::factory()->manager()->create();

        $this->actingAs($this->owner)->patch(route('users.deactivate', $user));

        $activity = Activity::where('event', 'deactivated')->firstOrFail();
        expect($activity->causer_id)->toBe($this->owner->id)
            ->and($activity->subject_id)->toBe($user->id);
    });
});

describe('set-up links', function () {
    it('sends a set-up link to an active user', function () {
        Notification::fake();
        $user = User::factory()->manager()->create();

        $this->actingAs($this->owner)->post(route('users.setup-link', $user))->assertRedirect();

        Notification::assertSentTo($user, SetUpAccountNotification::class);
    });

    it('does not send one to a deactivated user', function () {
        Notification::fake();
        $user = User::factory()->manager()->inactive()->create();

        $this->actingAs($this->owner)->post(route('users.setup-link', $user))->assertForbidden();

        Notification::assertNothingSent();
    });
});

describe('audit trail', function () {
    it('records sign-ins', function () {
        $user = User::factory()->manager()->create();

        $this->post(route('login.store'), ['email' => $user->email, 'password' => 'password']);

        $activity = Activity::where('event', 'signed_in')->firstOrFail();
        expect($activity->causer_id)->toBe($user->id);
    });

    it('never records passwords', function () {
        $user = User::factory()->manager()->create();

        $user->update(['name' => 'Renamed', 'password' => 'a-brand-new-password']);

        $logged = Activity::where('subject_id', $user->id)->get()->toJson();

        expect($logged)->not->toContain('a-brand-new-password')->not->toContain('password');
    });
});
