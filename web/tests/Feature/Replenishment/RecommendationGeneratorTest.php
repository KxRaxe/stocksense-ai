<?php

use App\Enums\RecommendationStatus;
use App\Enums\RiskLevel;
use App\Enums\StockMovementType;
use App\Models\Category;
use App\Models\ForecastRun;
use App\Models\Location;
use App\Models\Product;
use App\Models\Recommendation;
use App\Models\User;
use App\Services\Inventory\StockService;
use App\Services\Replenishment\GenerationResult;
use App\Services\Replenishment\RecommendationDecisions;
use App\Services\Replenishment\RecommendationGenerator;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;

/*
 * Forecasts of 70 a week (10 a day) from Monday 5 October 2026, "today" being that Monday.
 * A product with a 7-day lead time and a 7-day review period therefore has a reorder point
 * of 70 and an order-up-to level of 140, before any safety stock. See the calculator tests.
 */

beforeEach(function () {
    $this->seed(RolesAndPermissionsSeeder::class);
    config(['replenishment.review_days' => 7, 'replenishment.overstock_days' => 90]);

    $this->category = Category::factory()->create(['service_level' => 95]);
    $this->nails = Product::factory()->for($this->category)->create(['sku' => 'HW-1', 'name' => 'Nails', 'lead_time_days' => 7, 'moq' => 1, 'pack_size' => 1]);
});

function advice(): GenerationResult
{
    return app(RecommendationGenerator::class)->generate(testToday());
}

function openFor(Product $product): ?Recommendation
{
    return Recommendation::open()->where('product_id', $product->id)->first();
}

describe('before there is a forecast', function () {
    it('does nothing', function () {
        putInStock($this->nails, 10);

        $result = advice();

        expect($result->run)->toBeNull()
            ->and($result->assessed())->toBe(0)
            ->and(Recommendation::count())->toBe(0);
    });

    it('is not fooled by a forecast that failed or is still running', function () {
        putInStock($this->nails, 10);
        ForecastRun::create(['granularity' => 'week', 'horizon' => 8, 'status' => 'failed', 'location_id' => Location::defaultLocation()->id]);
        ForecastRun::create(['granularity' => 'month', 'horizon' => 3, 'status' => 'running', 'location_id' => Location::defaultLocation()->id]);

        expect(advice()->run)->toBeNull();
    });
});

describe('what it opens', function () {
    it('opens a pending recommendation for a product that will run out, with the figures behind it', function () {
        putInStock($this->nails, 40);
        $run = forecastWith([$this->nails->id => 70]);

        $result = advice();
        $recommendation = openFor($this->nails);

        expect($result->run->id)->toBe($run->id)
            ->and($result->created)->toBe(1)
            ->and($recommendation->status)->toBe(RecommendationStatus::Pending)
            ->and($recommendation->risk_level)->toBe(RiskLevel::Critical)
            ->and($recommendation->forecast_run_id)->toBe($run->id)
            ->and($recommendation->location_id)->toBe(Location::defaultLocation()->id)
            ->and($recommendation->on_hand)->toBe(40)
            ->and($recommendation->on_order)->toBe(0)
            ->and($recommendation->lead_time_days)->toBe(7)
            ->and((float) $recommendation->lead_time_demand)->toBe(70.0)
            ->and($recommendation->safety_stock)->toBe(0)
            ->and($recommendation->reorder_point)->toBe(70)
            ->and($recommendation->order_up_to)->toBe(140)
            ->and($recommendation->recommended_qty)->toBe(100)       // 140 - 40
            ->and($recommendation->order_by_date->toDateString())->toBe('2026-10-05')
            ->and($recommendation->explanation)->toContain('Order 100 now')
            ->and($recommendation->final_qty)->toBeNull()
            ->and($recommendation->decided_by)->toBeNull();
    });

    it('opens one for a low product, and for one to watch', function () {
        $low = Product::factory()->for($this->category)->create(['lead_time_days' => 7]);
        $watch = Product::factory()->for($this->category)->create(['lead_time_days' => 7]);
        putInStock($this->nails, 70);
        putInStock($low, 70);
        putInStock($watch, 100);
        forecastWith([$this->nails->id => 70, $low->id => 70, $watch->id => 70]);

        $result = advice();

        expect(openFor($low)->risk_level)->toBe(RiskLevel::Low)
            ->and(openFor($watch)->risk_level)->toBe(RiskLevel::Watch)
            ->and(openFor($watch)->status)->toBe(RecommendationStatus::Pending)
            ->and(openFor($watch)->order_by_date->toDateString())->toBe('2026-10-08')
            ->and($result->count(RiskLevel::Low))->toBe(2);
    });

    it('opens an information row for an overstocked product, nothing to decide', function () {
        putInStock($this->nails, 5000);
        forecastWith([$this->nails->id => 70]);

        advice();

        expect(openFor($this->nails))
            ->risk_level->toBe(RiskLevel::Overstock)
            ->status->toBe(RecommendationStatus::Info)
            ->recommended_qty->toBe(0)
            ->order_by_date->toBeNull();
    });

    it('opens nothing for a product that is comfortably stocked', function () {
        putInStock($this->nails, 300);
        forecastWith([$this->nails->id => 70]);

        $result = advice();

        expect(Recommendation::count())->toBe(0)
            ->and($result->count(RiskLevel::Ok))->toBe(1)
            ->and($result->assessed())->toBe(1);
    });

    it('counts products at each level', function () {
        $other = Product::factory()->for($this->category)->create(['lead_time_days' => 7]);
        putInStock($this->nails, 10);
        putInStock($other, 300);
        forecastWith([$this->nails->id => 70, $other->id => 70]);

        $result = advice();

        expect($result->byRisk)->toBe(['critical' => 1, 'ok' => 1]);
    });

    it('treats a product with no stock record as having none', function () {
        forecastWith([$this->nails->id => 70]);

        advice();

        expect(openFor($this->nails))->risk_level->toBe(RiskLevel::Critical)->on_hand->toBe(0);
    });
});

describe('what it takes into account', function () {
    it('counts what is on order, so the same product is not recommended twice', function () {
        putInStock($this->nails, 20, onOrder: 200);
        forecastWith([$this->nails->id => 70]);

        advice();

        // Position 220 is comfortable: nothing to do, though the shelf alone would be critical.
        expect(openFor($this->nails))->toBeNull();
    });

    it('uses the product\'s own lead time, minimum order and pack size', function () {
        $slow = Product::factory()->for($this->category)->create(['lead_time_days' => 14, 'moq' => 50, 'pack_size' => 24]);
        putInStock($slow, 100);
        forecastWith([$slow->id => 70]);

        advice();

        // 14 days of 10 = 140 > 100 on hand: critical. Up to 210, gap 110, packs of 24: 120.
        expect(openFor($slow))->risk_level->toBe(RiskLevel::Critical)->lead_time_days->toBe(14)->recommended_qty->toBe(120);
    });

    it('uses a reorder point and safety stock set for the product', function () {
        $set = Product::factory()->for($this->category)->create(['lead_time_days' => 7, 'reorder_point_override' => 200]);
        putInStock($set, 150);
        forecastWith([$set->id => 70]);

        advice();

        expect(openFor($set))->reorder_point->toBe(200)->safety_stock->toBe(130)->risk_level->toBe(RiskLevel::Low);
    });

    it('sizes safety stock from how far off forecasts for the product have been', function () {
        putInStock($this->nails, 103);
        forecastWith([$this->nails->id => 70], errors: [$this->nails->id => 14]);

        advice();

        // 14 a week over 14 days: 19.8, x 1.645 = 32.6, so 33.
        expect(openFor($this->nails))->safety_stock->toBe(33)->reorder_point->toBe(103)->risk_level->toBe(RiskLevel::Low);
    });

    it('falls back on the width of the forecast\'s range when the run has no typical error for a product', function () {
        putInStock($this->nails, 103);
        forecastWith([$this->nails->id => 70], run: ['residual_std' => []]);   // each week 56 to 84: 28 wide, 28 / 2.563 = 10.9 a week

        advice();

        // 10.9 over 14 days is 15.5, x 1.645 = 25.5, so 26.
        expect(openFor($this->nails)->safety_stock)->toBe(26);
    });

    it('asks for more safety stock where the category has a higher service level', function () {
        $careful = Category::factory()->create(['service_level' => 99]);
        $vital = Product::factory()->for($careful)->create(['lead_time_days' => 7]);
        putInStock($this->nails, 60);
        putInStock($vital, 60);
        forecastWith([$this->nails->id => 70, $vital->id => 70], errors: [$this->nails->id => 14, $vital->id => 14]);

        advice();

        expect(openFor($vital)->safety_stock)->toBeGreaterThan(openFor($this->nails)->safety_stock)
            ->and(openFor($vital)->safety_stock)->toBe(47)
            ->and(openFor($this->nails)->safety_stock)->toBe(33);
    });

    it('goes by the latest weekly forecast, falling back on monthly if there is none', function () {
        putInStock($this->nails, 40);
        $monthly = completedRun(['granularity' => 'month', 'horizon' => 3, 'as_of' => '2026-09-01']);
        forecastFor($monthly, $this->nails, [['2026-10-01', 310, 250, 370], ['2026-11-01', 300, 240, 360], ['2026-12-01', 310, 250, 370]]);

        expect(advice()->run->id)->toBe($monthly->id);

        $oldWeekly = forecastWith([$this->nails->id => 70]);
        $newWeekly = forecastWith([$this->nails->id => 70]);

        expect(advice()->run->id)->toBe($newWeekly->id)
            ->and($oldWeekly->id)->not->toBe($newWeekly->id);
    });

    it('forecasts of months work too', function () {
        putInStock($this->nails, 40);
        $monthly = completedRun(['granularity' => 'month', 'horizon' => 3, 'as_of' => '2026-09-01', 'residual_std' => [$this->nails->id => 0]]);
        forecastFor($monthly, $this->nails, [['2026-10-01', 310, 250, 370], ['2026-11-01', 300, 240, 360], ['2026-12-01', 310, 250, 370]]);

        advice();

        // 10 a day all through October: the same as the weekly case.
        expect(openFor($this->nails))->risk_level->toBe(RiskLevel::Critical)->recommended_qty->toBe(100);
    });

    it('leaves out archived products and products with no forecast', function () {
        $archived = Product::factory()->for($this->category)->archived()->create();
        $unforecast = Product::factory()->for($this->category)->create();
        putInStock($archived, 0);
        putInStock($unforecast, 0);
        putInStock($this->nails, 10);
        forecastWith([$archived->id => 70, $this->nails->id => 70]);

        advice();

        expect(Recommendation::pluck('product_id')->all())->toBe([$this->nails->id]);
    });

    it('only looks at stock at the default location', function () {
        $branch = Location::create(['name' => 'Branch', 'code' => 'BR', 'is_default' => false]);
        putInStock($this->nails, 10);
        app(StockService::class)->record($this->nails, StockMovementType::Initial, 9000, location: $branch);
        forecastWith([$this->nails->id => 70]);

        advice();

        expect(openFor($this->nails)->on_hand)->toBe(10);
    });
});

describe('each product has at most one open recommendation, refreshed in place', function () {
    it('updates the same row on the next round, so nothing is duplicated', function () {
        putInStock($this->nails, 40);
        forecastWith([$this->nails->id => 70]);

        advice();
        $first = openFor($this->nails);

        app(StockService::class)->record($this->nails, StockMovementType::Sale, -10);
        $second = advice();

        $refreshed = openFor($this->nails);

        expect($second->created)->toBe(0)
            ->and($second->updated)->toBe(1)
            ->and(Recommendation::count())->toBe(1)
            ->and($refreshed->id)->toBe($first->id)
            ->and($refreshed->on_hand)->toBe(30)
            ->and($refreshed->recommended_qty)->toBe(110);
    });

    it('closes the recommendation when stock arrives and the product is comfortable again', function () {
        putInStock($this->nails, 40);
        forecastWith([$this->nails->id => 70]);
        advice();

        app(StockService::class)->restock($this->nails, 500);
        $result = advice();

        expect($result->expired)->toBe(1)
            ->and(Recommendation::first()->status)->toBe(RecommendationStatus::Expired)
            ->and(openFor($this->nails))->toBeNull();
    });

    it('turns an information row into a recommendation if the product is no longer overstocked', function () {
        putInStock($this->nails, 5000);
        forecastWith([$this->nails->id => 70]);
        advice();
        $info = openFor($this->nails);

        app(StockService::class)->adjustTo($this->nails, 20);
        advice();

        expect(Recommendation::count())->toBe(1)
            ->and(openFor($this->nails)->id)->toBe($info->id)
            ->and(openFor($this->nails)->status)->toBe(RecommendationStatus::Pending)
            ->and(openFor($this->nails)->risk_level)->toBe(RiskLevel::Critical);
    });

    it('turns a recommendation into information if the product becomes overstocked', function () {
        putInStock($this->nails, 20);
        forecastWith([$this->nails->id => 70]);
        advice();

        app(StockService::class)->restock($this->nails, 6000);
        advice();

        expect(Recommendation::count())->toBe(1)
            ->and(openFor($this->nails)->status)->toBe(RecommendationStatus::Info);
    });

    it('closes the recommendation of a product that has dropped out of the forecast or been archived', function () {
        putInStock($this->nails, 10);
        forecastWith([$this->nails->id => 70]);
        advice();

        $this->nails->update(['is_active' => false]);
        $result = advice();

        expect($result->expired)->toBe(1)
            ->and(openFor($this->nails))->toBeNull();
    });

    it('is enforced by the database: no second open row for a product', function () {
        putInStock($this->nails, 10);
        forecastWith([$this->nails->id => 70]);
        advice();
        $row = openFor($this->nails)->replicate();

        expect(fn () => DB::transaction(fn () => $row->save()))->toThrow(UniqueConstraintViolationException::class);
    });

    it('may have a new open row once the old one is decided or closed', function () {
        putInStock($this->nails, 10);
        forecastWith([$this->nails->id => 70]);
        advice();
        Recommendation::query()->update(['status' => 'dismissed']);

        // (Snoozing is checked separately: here the dismissal has no snooze date.)
        advice();

        expect(Recommendation::count())->toBe(2)
            ->and(Recommendation::open()->count())->toBe(1);
    });
});

describe('decisions already made', function () {
    beforeEach(function () {
        // The clock, as well as the day the advice is worked out for, is Monday 5 October.
        $this->travelTo(testToday()->setTime(8, 0));
        $this->decider = User::factory()->manager()->create();
        putInStock($this->nails, 40);
        forecastWith([$this->nails->id => 70]);
        advice();
    });

    it('are never touched by a later round', function () {
        $accepted = app(RecommendationDecisions::class)->accept(openFor($this->nails), $this->decider, 'Ordered by phone');
        $before = $accepted->fresh()->only(['status', 'final_qty', 'recommended_qty', 'explanation', 'on_hand', 'note']);

        advice();
        advice();

        expect(Recommendation::find($accepted->id)->only(['status', 'final_qty', 'recommended_qty', 'explanation', 'on_hand', 'note']))->toBe($before);
    });

    it('lift a product out of the running once accepted, because the order counts', function () {
        app(RecommendationDecisions::class)->accept(openFor($this->nails), $this->decider);

        $result = advice();

        // 40 on hand + 100 on order = 140: not critical, and above the reorder point of 70.
        expect(openFor($this->nails))->toBeNull()
            ->and($result->created)->toBe(0)
            ->and($result->count(RiskLevel::Ok))->toBe(1);
    });

    it('bring the product back if the order is cancelled', function () {
        $accepted = app(RecommendationDecisions::class)->accept(openFor($this->nails), $this->decider);
        app(RecommendationDecisions::class)->cancel($accepted, $this->decider);

        advice();

        expect(openFor($this->nails))->risk_level->toBe(RiskLevel::Critical);
    });

    it('keep a dismissed product quiet for a while, and only a while', function () {
        app(RecommendationDecisions::class)->dismiss(openFor($this->nails), $this->decider);
        $dismissed = Recommendation::first();

        expect($dismissed->snoozed_until->toDateString())->toBe('2026-10-12');   // 7 days from the 5th

        $during = app(RecommendationGenerator::class)->generate(testToday()->addDays(3));
        expect($during->snoozed)->toBe(1)
            ->and($during->created)->toBe(0)
            ->and(openFor($this->nails))->toBeNull();

        // The snooze runs to the end of the 12th.
        expect(app(RecommendationGenerator::class)->generate(testToday()->addDays(7))->snoozed)->toBe(1);

        $after = app(RecommendationGenerator::class)->generate(testToday()->addDays(8));
        expect($after->snoozed)->toBe(0)
            ->and($after->created)->toBe(1)
            ->and(openFor($this->nails))->not->toBeNull();
    });

    it('do not hold back an unrelated product', function () {
        $other = Product::factory()->for($this->category)->create(['lead_time_days' => 7]);
        putInStock($other, 10);
        forecastWith([$this->nails->id => 70, $other->id => 70]);
        app(RecommendationDecisions::class)->dismiss(openFor($this->nails), $this->decider);

        advice();

        expect(openFor($other))->not->toBeNull()
            ->and(openFor($this->nails))->toBeNull();
    });
});

describe('what it reports as critical', function () {
    it('lists the open critical recommendations, with their products', function () {
        $other = Product::factory()->for($this->category)->create(['lead_time_days' => 7, 'name' => 'Cement']);
        $fine = Product::factory()->for($this->category)->create(['lead_time_days' => 7]);
        putInStock($this->nails, 10);
        putInStock($other, 10);
        putInStock($fine, 300);
        forecastWith([$this->nails->id => 70, $other->id => 70, $fine->id => 70]);

        $result = advice();

        expect($result->critical->pluck('product.name')->sort()->values()->all())->toBe(['Cement', 'Nails']);
    });

    it('still lists a product that was critical already, so the daily alert can decide', function () {
        putInStock($this->nails, 10);
        forecastWith([$this->nails->id => 70]);
        advice();

        expect(advice()->critical)->toHaveCount(1);
    });

    it('leaves out products that are only low, overstocked or snoozed', function () {
        $low = Product::factory()->for($this->category)->create(['lead_time_days' => 7]);
        putInStock($this->nails, 5000);
        putInStock($low, 70);
        forecastWith([$this->nails->id => 70, $low->id => 70]);

        expect(advice()->critical)->toHaveCount(0);
    });
});
