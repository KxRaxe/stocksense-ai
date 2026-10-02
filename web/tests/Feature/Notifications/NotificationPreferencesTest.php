<?php

use App\Enums\NotificationType;
use App\Models\NotificationPreference;
use App\Models\User;
use App\Services\Notifications\NotificationPreferences;
use Database\Seeders\RolesAndPermissionsSeeder;

beforeEach(function () {
    $this->seed(RolesAndPermissionsSeeder::class);

    $this->owner = User::factory()->owner()->create();
    $this->manager = User::factory()->manager()->create();
    $this->staff = User::factory()->inventoryStaff()->create();
    $this->preferences = app(NotificationPreferences::class);
});

describe('what each role could receive', function () {
    it('is everything for the Owner and Manager', function (string $role) {
        expect(array_keys($this->preferences->for($this->{$role})))->toBe(['critical_stock', 'replenishment_digest', 'forecast_run', 'import_errors']);
    })->with(['owner', 'manager']);

    it('is only import problems for inventory staff, who cannot decide about reordering or see forecasts', function () {
        expect(array_keys($this->preferences->for($this->staff)))->toBe(['import_errors']);
    });

    it('is nothing for someone with no role', function () {
        expect($this->preferences->for(User::factory()->create()))->toBe([]);
    });

    it('follows what each kind needs', function () {
        expect(NotificationType::CriticalStock->eligible($this->manager))->toBeTrue()
            ->and(NotificationType::CriticalStock->eligible($this->staff))->toBeFalse()
            ->and(NotificationType::ForecastRun->eligible($this->staff))->toBeFalse()
            ->and(NotificationType::ImportErrors->eligible($this->staff))->toBeTrue();
    });
});

describe('the defaults', function () {
    it('are in the app and by email, with a weekly digest', function () {
        $settings = $this->preferences->for($this->manager);

        expect($settings['critical_stock'])->toBe(['mail' => true, 'database' => true, 'digest' => null])
            ->and($settings['replenishment_digest'])->toBe(['mail' => true, 'database' => true, 'digest' => 'weekly'])
            ->and($this->preferences->channels($this->manager, NotificationType::CriticalStock))->toBe(['mail', 'database'])
            ->and($this->preferences->digestFrequency($this->manager))->toBe('weekly');
    });

    it('store nothing until a choice is changed', function () {
        $this->preferences->for($this->manager);
        $this->preferences->channels($this->manager, NotificationType::CriticalStock);

        expect(NotificationPreference::count())->toBe(0);
    });
});

describe('choosing', function () {
    it('can turn email off and keep the app', function () {
        $this->preferences->update($this->manager, ['critical_stock' => ['mail' => false, 'database' => true]]);

        expect($this->preferences->channels($this->manager, NotificationType::CriticalStock))->toBe(['database']);
    });

    it('can turn the app off and keep email', function () {
        $this->preferences->update($this->manager, ['critical_stock' => ['mail' => true, 'database' => false]]);

        expect($this->preferences->channels($this->manager, NotificationType::CriticalStock))->toBe(['mail']);
    });

    it('can turn both off, which sends nothing', function () {
        $this->preferences->update($this->manager, ['forecast_run' => ['mail' => false, 'database' => false]]);

        expect($this->preferences->channels($this->manager, NotificationType::ForecastRun))->toBe([]);
    });

    it('can choose how often the digest comes', function (string $frequency) {
        $this->preferences->update($this->manager, ['replenishment_digest' => ['mail' => true, 'database' => true, 'digest' => $frequency]]);

        expect($this->preferences->digestFrequency($this->manager))->toBe($frequency);
    })->with(['daily', 'weekly', 'off']);

    it('sends no digest at all when it is set to never, whatever the channels', function () {
        $this->preferences->update($this->manager, ['replenishment_digest' => ['mail' => true, 'database' => true, 'digest' => 'off']]);

        expect($this->preferences->channels($this->manager, NotificationType::Digest))->toBe([]);
    });

    it('falls back to a weekly digest if no frequency is given', function () {
        $this->preferences->update($this->manager, ['replenishment_digest' => ['mail' => true, 'database' => true]]);

        expect($this->preferences->digestFrequency($this->manager))->toBe('weekly');
    });

    it('changes only the kinds given, leaving the rest as they were', function () {
        $this->preferences->update($this->manager, ['critical_stock' => ['mail' => false, 'database' => true]]);
        $this->preferences->update($this->manager, ['forecast_run' => ['mail' => false, 'database' => false]]);

        expect($this->preferences->channels($this->manager, NotificationType::CriticalStock))->toBe(['database'])
            ->and($this->preferences->channels($this->manager, NotificationType::ForecastRun))->toBe([])
            ->and($this->preferences->channels($this->manager, NotificationType::ImportErrors))->toBe(['mail', 'database']);
    });

    it('can be changed again', function () {
        $this->preferences->update($this->manager, ['critical_stock' => ['mail' => false, 'database' => false]]);
        $this->preferences->update($this->manager, ['critical_stock' => ['mail' => true, 'database' => true]]);

        expect($this->preferences->channels($this->manager, NotificationType::CriticalStock))->toBe(['mail', 'database'])
            ->and(NotificationPreference::where('user_id', $this->manager->id)->count())->toBe(1);
    });

    it('ignores a kind the person could not receive', function () {
        $this->preferences->update($this->staff, ['critical_stock' => ['mail' => true, 'database' => true]]);

        expect(NotificationPreference::count())->toBe(0)
            ->and($this->preferences->channels($this->staff, NotificationType::CriticalStock))->toBe([]);
    });

    it('ignores a kind that does not exist', function () {
        $this->preferences->update($this->manager, ['nonsense' => ['mail' => false, 'database' => false]]);

        expect(NotificationPreference::count())->toBe(0);
    });

    it('keeps each person\'s choices to themselves', function () {
        $this->preferences->update($this->manager, ['critical_stock' => ['mail' => false, 'database' => false]]);

        expect($this->preferences->channels($this->owner, NotificationType::CriticalStock))->toBe(['mail', 'database'])
            ->and($this->preferences->channels($this->manager, NotificationType::CriticalStock))->toBe([]);
    });
});

describe('who is not eligible', function () {
    it('has no digest frequency', function () {
        expect($this->preferences->digestFrequency($this->staff))->toBeNull();
    });

    it('gets no channels for anything they could not receive', function () {
        expect($this->preferences->channels($this->staff, NotificationType::Digest))->toBe([])
            ->and($this->preferences->channels($this->staff, NotificationType::ForecastRun))->toBe([]);
    });
});
