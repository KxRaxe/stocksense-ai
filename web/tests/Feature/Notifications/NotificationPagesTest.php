<?php

use App\Models\NotificationPreference;
use App\Models\User;
use App\Notifications\CriticalStockNotification;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Support\Str;

beforeEach(function () {
    $this->seed(RolesAndPermissionsSeeder::class);

    $this->manager = User::factory()->manager()->create();
    $this->staff = User::factory()->inventoryStaff()->create();
});

/**
 * Puts a notification straight into a person's list.
 *
 * @param  array<string, mixed>  $change
 */
function giveNotification(User $user, array $change = []): string
{
    $id = (string) Str::uuid();

    $user->notifications()->create([
        'id' => $id,
        'type' => CriticalStockNotification::class,
        'data' => ['type' => 'critical_stock', 'title' => 'Critical stock: 2 products may run out', 'message' => 'Nails and Cement may run out.', 'url' => '/recommendations?risk=critical'],
        'read_at' => null,
        ...$change,
    ]);

    return $id;
}

describe('the bell', function () {
    it('is not there for a guest', function () {
        $this->get(route('login'))->assertInertia(fn ($page) => $page->where('bell', null));
    });

    it('counts what is unread and carries the latest few', function () {
        giveNotification($this->manager, ['read_at' => now()]);
        $second = giveNotification($this->manager, ['created_at' => now()->addMinute()]);

        $this->actingAs($this->manager)->get(route('dashboard'))
            ->assertInertia(fn ($page) => $page
                ->where('bell.unread', 1)
                ->has('bell.recent', 2)
                ->where('bell.recent.0', fn ($first) => $first['id'] === $second
                    && $first['title'] === 'Critical stock: 2 products may run out'
                    && $first['message'] === 'Nails and Cement may run out.'
                    && $first['url'] === '/recommendations?risk=critical'
                    && $first['read'] === false
                    && $first['type'] === 'critical_stock'));
    });

    it('shows at most eight, but counts them all', function () {
        foreach (range(1, 11) as $_) {
            giveNotification($this->manager);
        }

        $this->actingAs($this->manager)->get(route('dashboard'))
            ->assertInertia(fn ($page) => $page->where('bell.unread', 11)->has('bell.recent', 8));
    });

    it('is each person\'s own', function () {
        giveNotification($this->staff);

        $this->actingAs($this->manager)->get(route('dashboard'))
            ->assertInertia(fn ($page) => $page->where('bell.unread', 0)->where('bell.recent', []));
    });

    it('is still there on the notifications page, whose own list has another name', function () {
        giveNotification($this->manager);
        giveNotification($this->manager, ['read_at' => now()]);

        $this->actingAs($this->manager)->get(route('notifications.index'))
            ->assertInertia(fn ($page) => $page
                ->where('bell.unread', 1)
                ->has('bell.recent', 2)
                ->where('notifications.total', 2));
    });
});

describe('the list', function () {
    it('lists them newest first, read and unread', function () {
        $old = giveNotification($this->manager, ['read_at' => now(), 'created_at' => now()->subDay()]);
        $new = giveNotification($this->manager, ['created_at' => now()]);

        $this->actingAs($this->manager)->get(route('notifications.index'))
            ->assertInertia(fn ($page) => $page
                ->component('notifications/index')
                ->where('notifications.total', 2)
                ->where('notifications.data', fn ($rows) => $rows->pluck('id')->all() === [$new, $old] && $rows->pluck('read')->all() === [false, true]));
    });

    it('pages through them', function () {
        foreach (range(1, 25) as $_) {
            giveNotification($this->manager);
        }

        $this->actingAs($this->manager)->get(route('notifications.index'))
            ->assertInertia(fn ($page) => $page->has('notifications.data', 20)->where('notifications.last_page', 2));
    });

    it('is for people who are signed in', function () {
        $this->get(route('notifications.index'))->assertRedirect(route('login'));
    });

    it('is open to every role, since everyone has some', function () {
        $this->actingAs($this->staff)->get(route('notifications.index'))->assertOk();
    });
});

describe('opening one', function () {
    it('marks it read and goes where it points', function () {
        $id = giveNotification($this->manager);

        $this->actingAs($this->manager)->get(route('notifications.open', $id))->assertRedirect('/recommendations?risk=critical');

        expect($this->manager->notifications()->find($id)->read_at)->not->toBeNull();
    });

    it('never leads off the site, whatever was stored', function (string $url) {
        $id = giveNotification($this->manager, ['data' => ['type' => 'x', 'title' => 't', 'message' => 'm', 'url' => $url]]);

        $this->actingAs($this->manager)->get(route('notifications.open', $id))->assertRedirect('/');
    })->with(['https://evil.example/phish', '//evil.example/phish', 'javascript:alert(1)', '/\\evil.example', 'recommendations']);

    it('is not found when it belongs to someone else', function () {
        $theirs = giveNotification($this->staff);

        $this->actingAs($this->manager)->get(route('notifications.open', $theirs))->assertNotFound();

        expect($this->staff->notifications()->find($theirs)->read_at)->toBeNull();
    });

    it('is not found when there is no such notification', function () {
        $this->actingAs($this->manager)->get(route('notifications.open', (string) Str::uuid()))->assertNotFound();
        $this->actingAs($this->manager)->get('/notifications/not-a-uuid/open')->assertNotFound();
    });
});

describe('marking read', function () {
    it('marks one read and stays where the person was', function () {
        $id = giveNotification($this->manager);

        $this->actingAs($this->manager)->from('/dashboard')->post(route('notifications.read', $id))->assertRedirect('/dashboard');

        expect($this->manager->notifications()->find($id)->read_at)->not->toBeNull();
    });

    it('marks all read', function () {
        giveNotification($this->manager);
        giveNotification($this->manager);

        $this->actingAs($this->manager)->post(route('notifications.read-all'))->assertRedirect();

        expect($this->manager->unreadNotifications()->count())->toBe(0);
    });

    it('marks only the person\'s own', function () {
        giveNotification($this->staff);

        $this->actingAs($this->manager)->post(route('notifications.read-all'));

        expect($this->staff->unreadNotifications()->count())->toBe(1);
    });

    it('cannot mark someone else\'s', function () {
        $theirs = giveNotification($this->staff);

        $this->actingAs($this->manager)->post(route('notifications.read', $theirs))->assertNotFound();

        expect($this->staff->notifications()->find($theirs)->read_at)->toBeNull();
    });
});

describe('the settings page', function () {
    it('lists what a Manager could receive, with the defaults', function () {
        $this->actingAs($this->manager)->get(route('notification-preferences.edit'))
            ->assertInertia(fn ($page) => $page
                ->component('settings/notifications')
                ->where('types', fn ($types) => $types->pluck('type')->all() === ['critical_stock', 'replenishment_digest', 'forecast_run', 'import_errors']
                    && $types->first()['label'] === 'Critical stock alerts'
                    && $types->first()['mail'] === true
                    && $types->first()['database'] === true
                    && $types->first()['is_digest'] === false
                    && $types->get(1)['digest'] === 'weekly'
                    && $types->get(1)['is_digest'] === true));
    });

    it('lists only what inventory staff could receive', function () {
        $this->actingAs($this->staff)->get(route('notification-preferences.edit'))
            ->assertInertia(fn ($page) => $page->where('types', fn ($types) => $types->pluck('type')->all() === ['import_errors']));
    });

    it('offers daily, weekly and never for the digest', function () {
        $this->actingAs($this->manager)->get(route('notification-preferences.edit'))
            ->assertInertia(fn ($page) => $page->where('frequencies', [
                ['value' => 'daily', 'label' => 'Every day'],
                ['value' => 'weekly', 'label' => 'Every Monday'],
                ['value' => 'off', 'label' => 'Never'],
            ]));
    });

    it('saves what was chosen', function () {
        $this->actingAs($this->manager)
            ->put(route('notification-preferences.update'), ['settings' => [
                'critical_stock' => ['mail' => false, 'database' => true],
                'replenishment_digest' => ['mail' => true, 'database' => false, 'digest' => 'daily'],
            ]])
            ->assertRedirect()
            ->assertSessionHas('inertia.flash_data.toast.message', 'Notification settings saved.');

        $this->actingAs($this->manager)->get(route('notification-preferences.edit'))
            ->assertInertia(fn ($page) => $page->where('types', fn ($types) => $types->first()['mail'] === false
                && $types->first()['database'] === true
                && $types->get(1)['mail'] === true
                && $types->get(1)['database'] === false
                && $types->get(1)['digest'] === 'daily'));
    });

    it('refuses a frequency that does not exist, and a choice left blank', function () {
        $this->actingAs($this->manager)->put(route('notification-preferences.update'), ['settings' => ['replenishment_digest' => ['mail' => true, 'database' => true, 'digest' => 'hourly']]])
            ->assertSessionHasErrors('settings.replenishment_digest.digest');

        $this->actingAs($this->manager)->put(route('notification-preferences.update'), ['settings' => ['critical_stock' => ['mail' => true]]])
            ->assertSessionHasErrors('settings.critical_stock.database');

        $this->actingAs($this->manager)->put(route('notification-preferences.update'), [])->assertSessionHasErrors('settings');

        expect(NotificationPreference::count())->toBe(0);
    });

    it('ignores a kind the person could not receive', function () {
        $this->actingAs($this->staff)->put(route('notification-preferences.update'), ['settings' => ['critical_stock' => ['mail' => false, 'database' => false]]])->assertSessionHasNoErrors();

        expect(NotificationPreference::count())->toBe(0);
    });

    it('only changes the person\'s own settings', function () {
        $this->actingAs($this->manager)->put(route('notification-preferences.update'), ['settings' => ['critical_stock' => ['mail' => false, 'database' => false]]]);

        expect(NotificationPreference::where('user_id', $this->manager->id)->count())->toBe(1)
            ->and(NotificationPreference::where('user_id', '!=', $this->manager->id)->count())->toBe(0);
    });

    it('is for people who are signed in', function () {
        $this->get(route('notification-preferences.edit'))->assertRedirect(route('login'));
        $this->put(route('notification-preferences.update'), [])->assertRedirect(route('login'));
    });
});
