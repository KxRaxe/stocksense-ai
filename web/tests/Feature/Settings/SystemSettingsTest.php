<?php

use App\Enums\Role;
use App\Models\Category;
use App\Models\Product;
use App\Models\Recommendation;
use App\Models\User;
use App\Services\Products\ProductImportFields;
use App\Services\Replenishment\RecommendationDecisions;
use App\Services\Replenishment\RecommendationGenerator;
use App\Services\Settings\Settings;
use App\Services\Settings\SettingsSchema;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

beforeEach(function () {
    $this->seed(RolesAndPermissionsSeeder::class);
    Cache::forget(Settings::CACHE_KEY);

    $this->owner = User::factory()->owner()->create();
});

/**
 * Every setting as the form would send it, with some changed.
 *
 * @param  array<string, mixed>  $changes
 * @return array{values: array<string, mixed>}
 */
function settingsForm(array $changes = []): array
{
    return ['values' => [...app(Settings::class)->all(), ...$changes]];
}

describe('who may use it', function () {
    it('sends guests to log in', function (string $method, string $route) {
        $this->{$method}(route($route))->assertRedirect(route('login'));
    })->with([
        ['get', 'system-settings.edit'],
        ['put', 'system-settings.update'],
        ['post', 'system-settings.reset'],
    ]);

    it('is for the Owner only', function (Role $role, string $method, string $route) {
        $this->actingAs(User::factory()->withRole($role)->create())->{$method}(route($route), $method === 'put' ? settingsForm() : [])->assertForbidden();

        expect(DB::table('settings')->count())->toBe(0);
    })->with([Role::Manager, Role::InventoryStaff])->with([
        ['get', 'system-settings.edit'],
        ['put', 'system-settings.update'],
        ['post', 'system-settings.reset'],
    ]);

    it('opens for the Owner', function () {
        $this->actingAs($this->owner)->get(route('system-settings.edit'))->assertOk();
    });
});

describe('the page', function () {
    it('lists every setting in three groups, with what it does', function () {
        $this->actingAs($this->owner)->get(route('system-settings.edit'))
            ->assertInertia(fn ($page) => $page
                ->component('system-settings/edit')
                ->where('groups', fn ($groups) => $groups->pluck('name')->all() === ['Stock advice', 'Forecasts', 'Schedule']
                    && $groups->sum(fn ($group) => count($group['settings'])) === count(SettingsSchema::definitions()))
                ->where('has_changes', false)
                ->where('time_zone', 'Asia/Manila'));
    });

    it('gives each setting its value, default and limits', function () {
        $this->actingAs($this->owner)->get(route('system-settings.edit'))
            ->assertInertia(fn ($page) => $page->where('groups.0.settings.1', fn ($review) => $review['key'] === 'review_days'
                && $review['label'] === 'Review period'
                && $review['type'] === 'integer'
                && $review['unit'] === 'days'
                && $review['min'] === 1
                && $review['max'] === 60
                && $review['value'] === 7
                && $review['default'] === 7
                && $review['is_default'] === true
                && $review['help'] !== ''));
    });

    it('offers the weekdays for a choice', function () {
        $this->actingAs($this->owner)->get(route('system-settings.edit'))
            ->assertInertia(fn ($page) => $page->where('groups.2.settings', function ($settings) {
                $day = collect($settings)->firstWhere('key', 'forecast_weekly_day');

                return $day['type'] === 'choice'
                    && $day['value'] === 1
                    && count($day['choices']) === 7
                    && $day['choices'][0] === ['value' => 1, 'label' => 'Monday']
                    && $day['choices'][6] === ['value' => 0, 'label' => 'Sunday'];
            }));
    });

    it('shows what has been changed, and that something has', function () {
        app(Settings::class)->update(['review_days' => 14], $this->owner);

        $this->actingAs($this->owner)->get(route('system-settings.edit'))
            ->assertInertia(fn ($page) => $page
                ->where('has_changes', true)
                ->where('groups.0.settings.1', fn ($review) => $review['value'] === 14 && $review['default'] === 7 && $review['is_default'] === false));
    });
});

describe('saving', function () {
    it('saves and applies the changes, and says so', function () {
        $this->actingAs($this->owner)
            ->put(route('system-settings.update'), settingsForm(['review_days' => 14, 'digest_time' => '08:30']))
            ->assertRedirect()
            ->assertSessionHasNoErrors()
            ->assertSessionHas('inertia.flash_data.toast.message', 'Settings saved. They apply from now on.');

        expect(config('replenishment.review_days'))->toBe(14)
            ->and(config('replenishment.schedule.digest'))->toBe('08:30');
    });

    it('accepts what a browser sends: strings and "on"', function () {
        $this->actingAs($this->owner)
            ->put(route('system-settings.update'), settingsForm(['review_days' => '10', 'default_service_level' => '97.5', 'forecast_schedule' => false, 'forecast_weekly_day' => '3']))
            ->assertSessionHasNoErrors();

        expect(config('replenishment.review_days'))->toBe(10)
            ->and(config('replenishment.default_service_level'))->toBe(97.5)
            ->and(config('forecasting.schedule.enabled'))->toBeFalse()
            ->and(config('forecasting.schedule.weekly.day'))->toBe(3);
    });

    it('says when nothing was changed', function () {
        $this->actingAs($this->owner)
            ->put(route('system-settings.update'), settingsForm())
            ->assertSessionHas('inertia.flash_data.toast', ['type' => 'info', 'message' => 'Nothing was changed.']);
    });

    it('refuses values that make no sense, and saves none of it', function (string $key, mixed $value) {
        $this->actingAs($this->owner)
            ->put(route('system-settings.update'), settingsForm(['review_days' => 14, $key => $value]))
            ->assertSessionHasErrors("values.{$key}");

        expect(DB::table('settings')->count())->toBe(0);
    })->with([
        'review period of nothing' => ['review_days', 0],
        'review period of a year' => ['review_days', 365],
        'review period in words' => ['review_days', 'weekly'],
        'a fraction of a day' => ['overstock_days', 20.5],
        'overstock threshold too short' => ['overstock_days', 3],
        'service level of 100' => ['default_service_level', 100],
        'service level of 20' => ['default_service_level', 20],
        'weekly horizon of 40' => ['horizon_week', 40],
        'monthly horizon of 13' => ['horizon_month', 13],
        'a time that is not one' => ['advice_time', '25:99'],
        'a time with seconds' => ['digest_time', '07:00:00'],
        'a weekday that does not exist' => ['forecast_weekly_day', 7],
        'the 31st of the month' => ['forecast_monthly_day', 31],
        'a switch that is not on or off' => ['forecast_schedule', 'maybe'],
        'nothing at all' => ['snooze_days', null],
    ]);

    it('refuses a form with no settings in it', function () {
        $this->actingAs($this->owner)->put(route('system-settings.update'), [])->assertSessionHasErrors('values');
    });

    it('wants every setting, not some', function () {
        $values = settingsForm()['values'];
        unset($values['review_days']);

        $this->actingAs($this->owner)->put(route('system-settings.update'), ['values' => $values])->assertSessionHasErrors('values.review_days');
    });

    it('wants the digest to go out after the advice is refreshed', function (string $advice, string $digest) {
        $this->actingAs($this->owner)
            ->put(route('system-settings.update'), settingsForm(['advice_time' => $advice, 'digest_time' => $digest]))
            ->assertSessionHasErrors(['values.digest_time' => 'The digest must be sent after the recommendations are refreshed, so it is up to date.']);
    })->with([
        'before' => ['08:00', '07:00'],
        'at the same time' => ['08:00', '08:00'],
    ]);

    it('accepts a digest a minute after the advice', function () {
        $this->actingAs($this->owner)
            ->put(route('system-settings.update'), settingsForm(['advice_time' => '08:00', 'digest_time' => '08:01']))
            ->assertSessionHasNoErrors();
    });

    it('restores the defaults', function () {
        app(Settings::class)->update(['review_days' => 14, 'forecast_schedule' => false], $this->owner);

        $this->actingAs($this->owner)
            ->post(route('system-settings.reset'))
            ->assertRedirect()
            ->assertSessionHas('inertia.flash_data.toast.message', 'The default settings are back.');

        expect(DB::table('settings')->count())->toBe(0)
            ->and(config('replenishment.review_days'))->toBe(7)
            ->and(config('forecasting.schedule.enabled'))->toBeTrue();
    });
});

describe('what the settings change', function () {
    it('sets the review period the advice is worked out with', function () {
        $this->travelTo(testToday()->setTime(8, 0));
        $category = Category::factory()->create(['service_level' => 95]);
        $product = Product::factory()->for($category)->create(['lead_time_days' => 7]);
        putInStock($product, 100);
        forecastWith([$product->id => 70]);

        app(RecommendationGenerator::class)->generate(testToday());
        $weekly = Recommendation::open()->sole();

        $this->actingAs($this->owner)->put(route('system-settings.update'), settingsForm(['review_days' => 28]));
        app(RecommendationGenerator::class)->generate(testToday());
        $monthly = Recommendation::open()->sole();

        // A longer gap between orders means ordering up to a higher level.
        expect($monthly->order_up_to)->toBeGreaterThan($weekly->order_up_to);
    });

    it('sets how long a dismissed recommendation stays hidden', function () {
        $this->travelTo(testToday()->setTime(8, 0));
        $category = Category::factory()->create();
        $product = Product::factory()->for($category)->create(['lead_time_days' => 7]);
        putInStock($product, 10);
        forecastWith([$product->id => 70]);
        app(RecommendationGenerator::class)->generate(testToday());

        app(Settings::class)->update(['snooze_days' => 3], $this->owner);
        app(RecommendationDecisions::class)->dismiss(Recommendation::open()->sole(), $this->owner);

        expect(Recommendation::query()->latest('id')->first()->snoozed_until->toDateString())->toBe(testToday()->addDays(3)->toDateString());
    });

    it('sets the service level a new category starts with', function () {
        app(Settings::class)->update(['default_service_level' => 90], $this->owner);

        expect(ProductImportFields::newCategoryServiceLevel())->toBe('90.00');

        $this->actingAs($this->owner)->get(route('categories.create'))
            ->assertInertia(fn ($page) => $page->where('defaultServiceLevel', 90));
    });

    it('starts a new category at 95% unless told otherwise', function () {
        expect(ProductImportFields::newCategoryServiceLevel())->toBe('95.00');

        $this->actingAs($this->owner)->get(route('categories.create'))
            ->assertInertia(fn ($page) => $page->where('defaultServiceLevel', 95));
    });
});
