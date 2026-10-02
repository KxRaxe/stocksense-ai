<?php

use App\Enums\RecommendationStatus;
use App\Models\Category;
use App\Models\InventoryLevel;
use App\Models\Location;
use App\Models\Product;
use App\Models\Recommendation;
use App\Models\StockMovement;
use App\Models\User;
use App\Services\Inventory\StockService;
use App\Services\Replenishment\RecommendationDecisions;
use App\Services\Replenishment\RecommendationGenerator;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Validation\ValidationException;
use Spatie\Activitylog\Models\Activity;

beforeEach(function () {
    $this->seed(RolesAndPermissionsSeeder::class);
    $this->travelTo(testToday()->setTime(8, 0));

    $this->manager = User::factory()->manager()->create(['name' => 'Pat Manager']);
    $this->category = Category::factory()->create(['service_level' => 95]);
    $this->nails = Product::factory()->for($this->category)->create(['lead_time_days' => 7]);

    // 40 on hand, 70 a week forecast: critical, and 100 recommended.
    putInStock($this->nails, 40);
    forecastWith([$this->nails->id => 70]);
    app(RecommendationGenerator::class)->generate(testToday());

    $this->recommendation = Recommendation::open()->firstOrFail();
    $this->decisions = app(RecommendationDecisions::class);
});

function level(Product $product): InventoryLevel
{
    return InventoryLevel::where('product_id', $product->id)->where('location_id', Location::defaultLocation()->id)->firstOrFail();
}

describe('accepting', function () {
    it('records the decision and counts the quantity as on order', function () {
        $decided = $this->decisions->accept($this->recommendation, $this->manager, '  Phoned the supplier  ');

        expect($decided->status)->toBe(RecommendationStatus::Accepted)
            ->and($decided->final_qty)->toBe(100)
            ->and($decided->recommended_qty)->toBe(100)
            ->and($decided->decided_by)->toBe($this->manager->id)
            ->and($decided->decided_at->toDateTimeString())->toBe('2026-10-05 08:00:00')
            ->and($decided->note)->toBe('Phoned the supplier')
            ->and(level($this->nails)->on_order)->toBe(100);
    });

    it('does not touch the stock itself: nothing arrives until goods are received', function () {
        $movements = StockMovement::count();

        $this->decisions->accept($this->recommendation, $this->manager);

        expect(level($this->nails)->on_hand)->toBe(40)
            ->and(StockMovement::count())->toBe($movements);
    });

    it('treats a blank note as none', function () {
        expect($this->decisions->accept($this->recommendation, $this->manager, "   \n ")->note)->toBeNull();
    });

    it('is recorded in the audit log, with who, what and how much', function () {
        $this->decisions->accept($this->recommendation, $this->manager);

        $entry = Activity::where('event', 'recommendation_accepted')->firstOrFail();

        expect($entry->log_name)->toBe('replenishment')
            ->and($entry->causer_id)->toBe($this->manager->id)
            ->and($entry->subject_id)->toBe($this->recommendation->id)
            ->and($entry->description)->toBe('Recommendation accepted for 100')
            ->and($entry->properties['product_id'])->toBe($this->nails->id)
            ->and($entry->properties['quantity'])->toBe(100)
            ->and($entry->properties['risk'])->toBe('critical');
    });

    it('adds to what is already on order', function () {
        app(StockService::class)->addOnOrder($this->nails, 25);

        $this->decisions->accept($this->recommendation->fresh(), $this->manager);

        expect(level($this->nails)->on_order)->toBe(125);
    });

    it('is undone, as far as the stock goes, when the goods arrive', function () {
        $this->decisions->accept($this->recommendation, $this->manager);

        app(StockService::class)->restock($this->nails, 100);

        expect(level($this->nails)->on_order)->toBe(0)
            ->and(level($this->nails)->on_hand)->toBe(140)
            ->and(Recommendation::find($this->recommendation->id)->status)->toBe(RecommendationStatus::Accepted);
    });
});

describe('adjusting the quantity', function () {
    it('accepts a different quantity and orders that instead', function () {
        $decided = $this->decisions->adjust($this->recommendation, $this->manager, 150, 'Supplier has a deal on');

        expect($decided->status)->toBe(RecommendationStatus::Adjusted)
            ->and($decided->final_qty)->toBe(150)
            ->and($decided->recommended_qty)->toBe(100)   // what was advised is kept
            ->and($decided->note)->toBe('Supplier has a deal on')
            ->and(level($this->nails)->on_order)->toBe(150);
    });

    it('can be less than was recommended', function () {
        $decided = $this->decisions->adjust($this->recommendation, $this->manager, 30);

        expect($decided->final_qty)->toBe(30)
            ->and(level($this->nails)->on_order)->toBe(30);
    });

    it('is just an acceptance if the quantity is the one recommended', function () {
        expect($this->decisions->adjust($this->recommendation, $this->manager, 100)->status)->toBe(RecommendationStatus::Accepted);
    });

    it('says what was changed, in the audit log', function () {
        $this->decisions->adjust($this->recommendation, $this->manager, 150);

        expect(Activity::where('event', 'recommendation_adjusted')->firstOrFail()->description)->toBe('Recommendation accepted with 150 instead of 100');
    });

    it('refuses a quantity that makes no sense, and changes nothing', function (int $quantity) {
        expect(fn () => $this->decisions->adjust($this->recommendation, $this->manager, $quantity))
            ->toThrow(ValidationException::class);

        expect($this->recommendation->fresh()->status)->toBe(RecommendationStatus::Pending)
            ->and(level($this->nails)->on_order)->toBe(0);
    })->with([0, -5, 10_000_001]);

    it('puts the problem against the quantity field', function () {
        try {
            $this->decisions->adjust($this->recommendation, $this->manager, 0);
            $this->fail('Expected a ValidationException.');
        } catch (ValidationException $e) {
            expect($e->errors())->toHaveKey('quantity');
        }
    });
});

describe('dismissing', function () {
    it('records the decision and silences the product for a week', function () {
        $decided = $this->decisions->dismiss($this->recommendation, $this->manager, 'Discontinuing it');

        expect($decided->status)->toBe(RecommendationStatus::Dismissed)
            ->and($decided->final_qty)->toBeNull()
            ->and($decided->decided_by)->toBe($this->manager->id)
            ->and($decided->note)->toBe('Discontinuing it')
            ->and($decided->snoozed_until->toDateString())->toBe('2026-10-12');
    });

    it('does not count anything as on order', function () {
        $this->decisions->dismiss($this->recommendation, $this->manager);

        expect(level($this->nails)->on_order)->toBe(0);
    });

    it('is recorded in the audit log', function () {
        $this->decisions->dismiss($this->recommendation, $this->manager);

        expect(Activity::where('event', 'recommendation_dismissed')->firstOrFail()->causer_id)->toBe($this->manager->id);
    });

    it('follows the configured number of days', function () {
        config(['replenishment.snooze_days' => 3]);

        expect($this->decisions->dismiss($this->recommendation, $this->manager)->snoozed_until->toDateString())->toBe('2026-10-08');
    });
});

describe('cancelling an order', function () {
    beforeEach(function () {
        $this->accepted = $this->decisions->accept($this->recommendation, $this->manager);
    });

    it('takes the quantity off what is on order', function () {
        $cancelled = $this->decisions->cancel($this->accepted, $this->manager, 'Supplier is out of stock');

        expect($cancelled->status)->toBe(RecommendationStatus::Cancelled)
            ->and($cancelled->cancelled_by)->toBe($this->manager->id)
            ->and($cancelled->cancelled_at)->not->toBeNull()
            ->and($cancelled->note)->toBe('Supplier is out of stock')
            ->and(level($this->nails)->on_order)->toBe(0);
    });

    it('keeps who accepted it, and the original note when none is given', function () {
        $other = User::factory()->owner()->create();
        $accepted = Recommendation::find($this->accepted->id);
        $accepted->update(['note' => 'Original note']);

        $cancelled = $this->decisions->cancel($accepted, $other);

        expect($cancelled->decided_by)->toBe($this->manager->id)
            ->and($cancelled->cancelled_by)->toBe($other->id)
            ->and($cancelled->note)->toBe('Original note');
    });

    it('works on an order accepted with a different quantity', function () {
        // A second recommendation for the product, as a later round of advice could open one.
        $second = $this->recommendation->replicate();
        $second->status = RecommendationStatus::Pending;
        $second->final_qty = null;
        $second->save();

        $adjusted = $this->decisions->adjust($second, $this->manager, 60);

        // (the first acceptance is still on order too)
        expect(level($this->nails)->on_order)->toBe(160);

        $this->decisions->cancel($adjusted, $this->manager);

        expect(level($this->nails)->on_order)->toBe(100);
    });

    it('only takes off what is still on order, never going below zero, if part has arrived', function () {
        app(StockService::class)->restock($this->nails, 70);   // 30 of the 100 still to come

        $this->decisions->cancel($this->accepted, $this->manager);

        expect(level($this->nails)->on_order)->toBe(0)
            ->and(level($this->nails)->on_hand)->toBe(110);
    });

    it('is recorded in the audit log', function () {
        $this->decisions->cancel($this->accepted, $this->manager);

        expect(Activity::where('event', 'recommendation_cancelled')->firstOrFail()->causer_id)->toBe($this->manager->id);
    });

    it('cannot be done twice', function () {
        $this->decisions->cancel($this->accepted, $this->manager);

        expect(fn () => $this->decisions->cancel($this->accepted, $this->manager))->toThrow(ValidationException::class)
            ->and(level($this->nails)->on_order)->toBe(0);
    });

    it('cannot be done to something that was not accepted', function () {
        $other = Product::factory()->for($this->category)->create(['lead_time_days' => 7]);
        putInStock($other, 10);
        forecastWith([$this->nails->id => 70, $other->id => 70]);
        app(RecommendationGenerator::class)->generate(testToday());
        $pending = Recommendation::open()->where('product_id', $other->id)->firstOrFail();

        expect(fn () => $this->decisions->cancel($pending, $this->manager))->toThrow(ValidationException::class);

        $dismissed = $this->decisions->dismiss($pending, $this->manager);

        expect(fn () => $this->decisions->cancel($dismissed, $this->manager))->toThrow(ValidationException::class);
    });
});

describe('a decision is made once', function () {
    it('cannot be made again by someone else', function (string $second) {
        $this->decisions->accept($this->recommendation, $this->manager);

        expect(fn () => $this->decisions->{$second}($this->recommendation, User::factory()->owner()->create()))
            ->toThrow(ValidationException::class, 'already been dealt with');

        // Ordered once, not twice.
        expect(level($this->nails)->on_order)->toBe(100);
    })->with(['accept', 'dismiss']);

    it('cannot be adjusted after being accepted', function () {
        $this->decisions->accept($this->recommendation, $this->manager);

        expect(fn () => $this->decisions->adjust($this->recommendation, $this->manager, 50))->toThrow(ValidationException::class);
    });

    it('is checked against the database, not a stale copy: two people pressing Accept at once order once', function () {
        $first = Recommendation::find($this->recommendation->id);
        $second = Recommendation::find($this->recommendation->id);   // still looks pending in memory

        $this->decisions->accept($first, $this->manager);

        expect(fn () => $this->decisions->accept($second, User::factory()->owner()->create()))->toThrow(ValidationException::class)
            ->and(level($this->nails)->on_order)->toBe(100)
            ->and(Recommendation::find($this->recommendation->id)->decided_by)->toBe($this->manager->id);
    });

    it('cannot be made about advice that is no longer needed or is only information', function (RecommendationStatus $status) {
        $this->recommendation->update(['status' => $status]);

        expect(fn () => $this->decisions->accept($this->recommendation, $this->manager))->toThrow(ValidationException::class)
            ->and(level($this->nails)->on_order)->toBe(0);
    })->with([RecommendationStatus::Expired, RecommendationStatus::Info, RecommendationStatus::Cancelled]);

    it('cannot order nothing', function () {
        $this->recommendation->update(['recommended_qty' => 0]);

        expect(fn () => $this->decisions->accept($this->recommendation, $this->manager))
            ->toThrow(ValidationException::class, 'nothing to order');
    });
});
