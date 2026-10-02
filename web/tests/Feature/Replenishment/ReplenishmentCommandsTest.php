<?php

use App\Jobs\GenerateRecommendationsJob;
use App\Models\Category;
use App\Models\Product;
use App\Models\Recommendation;
use App\Models\User;
use App\Notifications\CriticalStockNotification;
use App\Notifications\ReplenishmentDigestNotification;
use App\Services\Notifications\NotificationPreferences;
use App\Services\Replenishment\RecommendationGenerator;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Queue;

beforeEach(function () {
    $this->seed(RolesAndPermissionsSeeder::class);
    Cache::flush();

    $this->owner = User::factory()->owner()->create();
    $this->manager = User::factory()->manager()->create();
    $this->category = Category::factory()->create();
    $this->nails = Product::factory()->for($this->category)->create(['name' => 'Nails', 'lead_time_days' => 7]);
});

/** A critical product, as of Monday 5 October 2026. */
function criticalNails(): void
{
    putInStock(test()->nails, 10);
    forecastWith([test()->nails->id => 70]);
}

describe('recommendations:generate', function () {
    it('queues the work by default', function () {
        Queue::fake();

        $this->artisan('recommendations:generate')->expectsOutputToContain('queued')->assertSuccessful();

        Queue::assertPushed(GenerateRecommendationsJob::class);
    });

    it('can do it now and says what it found', function () {
        $this->travelTo(testToday()->setTime(6, 0));
        Notification::fake();
        criticalNails();

        $this->artisan('recommendations:generate --sync')
            ->expectsOutputToContain('1 products assessed (1 critical, 0 low, 0 to watch, 0 overstocked). 1 opened, 0 refreshed, 0 closed')
            ->assertSuccessful();

        expect(Recommendation::open()->count())->toBe(1);
    });

    it('tells the deciders about critical products when run now', function () {
        $this->travelTo(testToday()->setTime(6, 0));
        Notification::fake();
        criticalNails();

        $this->artisan('recommendations:generate --sync')->assertSuccessful();

        Notification::assertSentTo([$this->owner, $this->manager], CriticalStockNotification::class);
    });

    it('says so when there is no forecast to go on', function () {
        $this->artisan('recommendations:generate --sync')->expectsOutputToContain('no completed forecast')->assertSuccessful();

        expect(Recommendation::count())->toBe(0);
    });
});

describe('notifications:digest', function () {
    beforeEach(function () {
        Notification::fake();
        criticalNails();
        app(RecommendationGenerator::class)->generate(testToday());

        app(NotificationPreferences::class)->update($this->manager, ['replenishment_digest' => ['mail' => true, 'database' => true, 'digest' => 'daily']]);
    });

    it('sends the weekly digest on Mondays, as well as the daily one', function () {
        $this->travelTo(testToday()->setTime(7, 0));   // a Monday

        $this->artisan('notifications:digest')->expectsOutputToContain('2 people')->assertSuccessful();

        Notification::assertSentTo([$this->owner, $this->manager], ReplenishmentDigestNotification::class);
    });

    it('sends only the daily digest on other days', function () {
        $this->travelTo(testToday()->addDay()->setTime(7, 0));   // a Tuesday

        $this->artisan('notifications:digest')->expectsOutputToContain('1 person')->assertSuccessful();

        Notification::assertSentTo($this->manager, ReplenishmentDigestNotification::class);
        Notification::assertNotSentTo($this->owner, ReplenishmentDigestNotification::class);
    });

    it('can be told to include the weekly digest on any day', function () {
        $this->travelTo(testToday()->addDays(2)->setTime(7, 0));

        $this->artisan('notifications:digest --weekly')->assertSuccessful();

        Notification::assertSentTo([$this->owner, $this->manager], ReplenishmentDigestNotification::class);
    });

    it('says so when there is nothing to send', function () {
        Recommendation::query()->update(['status' => 'dismissed']);
        $this->travelTo(testToday()->setTime(7, 0));

        $this->artisan('notifications:digest')->expectsOutputToContain('Nothing to send')->assertSuccessful();

        Notification::assertNothingSent();
    });
});

describe('the schedule', function () {
    it('refreshes the advice every morning and sends the digest after it', function () {
        $events = collect(app(Schedule::class)->events())
            ->mapWithKeys(fn ($event) => [trim(preg_replace("/^.*artisan'?\s+/", '', $event->command)) => $event->expression]);

        expect($events['recommendations:generate'])->toBe('0 6 * * *')
            ->and($events['notifications:digest'])->toBe('0 7 * * *');
    });
});
