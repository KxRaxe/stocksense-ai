<?php

use App\Enums\RecommendationStatus;
use App\Jobs\GenerateRecommendationsJob;
use App\Models\Category;
use App\Models\ForecastRun;
use App\Models\InventoryLevel;
use App\Models\Product;
use App\Models\Recommendation;
use App\Models\User;
use App\Services\Replenishment\RecommendationGenerator;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Support\Facades\Queue;

beforeEach(function () {
    $this->seed(RolesAndPermissionsSeeder::class);
    $this->travelTo(testToday()->setTime(8, 0));

    $this->manager = User::factory()->manager()->create(['name' => 'Pat Manager']);
    $this->staff = User::factory()->inventoryStaff()->create();
    $this->hardware = Category::factory()->create(['name' => 'Hardware', 'service_level' => 95]);
    $this->food = Category::factory()->create(['name' => 'Food', 'service_level' => 95]);
});

function stockedProduct(string $name, string $sku, Category $category, int $onHand): Product
{
    $product = Product::factory()->for($category)->create(['name' => $name, 'sku' => $sku, 'lead_time_days' => 7, 'unit' => 'pc']);
    putInStock($product, $onHand);

    return $product;
}

/**
 * Nails (critical), Cement (low), Paint (watch), Rice (overstock) and Salt (comfortable), all forecast at 70 a week.
 */
function adviceForFive(): void
{
    $products = [
        stockedProduct('Nails', 'HW-1', test()->hardware, 10),
        stockedProduct('Cement', 'HW-2', test()->hardware, 70),
        stockedProduct('Paint', 'HW-3', test()->hardware, 100),
        stockedProduct('Rice', 'FB-1', test()->food, 9000),
        stockedProduct('Salt', 'FB-2', test()->food, 300),
    ];

    forecastWith(collect($products)->mapWithKeys(fn (Product $product) => [$product->id => 70])->all());
    app(RecommendationGenerator::class)->generate(testToday());
}

describe('who may look and who may decide', function () {
    it('sends guests to log in', function () {
        $this->get(route('recommendations.index'))->assertRedirect(route('login'));
    });

    it('lets everyone with the view permission look, including inventory staff', function (string $role) {
        $this->actingAs(User::factory()->{$role}()->create())->get(route('recommendations.index'))->assertOk();
    })->with(['owner', 'manager', 'inventoryStaff']);

    it('lets only the Owner and Manager decide', function (string $action) {
        adviceForFive();
        $recommendation = Recommendation::open()->first();

        $this->actingAs($this->staff)->post(route("recommendations.{$action}", $recommendation), ['quantity' => 50])->assertForbidden();

        expect($recommendation->fresh()->status)->toBe(RecommendationStatus::Pending);
    })->with(['accept', 'adjust', 'dismiss']);

    it('sends guests to log in rather than letting them decide', function (string $action) {
        adviceForFive();
        $recommendation = Recommendation::open()->first();

        $this->post(route("recommendations.{$action}", $recommendation), ['quantity' => 50])->assertRedirect(route('login'));

        expect($recommendation->fresh()->status)->toBe(RecommendationStatus::Pending);
    })->with(['accept', 'adjust', 'dismiss']);

    it('keeps staff from cancelling and from refreshing', function () {
        adviceForFive();
        $recommendation = Recommendation::open()->first();

        $this->actingAs($this->staff)->post(route('recommendations.cancel', $recommendation))->assertForbidden();
        $this->actingAs($this->staff)->post(route('recommendations.refresh'))->assertForbidden();
    });

    it('tells the page whether the person may decide', function () {
        $this->actingAs($this->manager)->get(route('recommendations.index'))->assertInertia(fn ($page) => $page->where('can.decide', true));
        $this->actingAs($this->staff)->get(route('recommendations.index'))->assertInertia(fn ($page) => $page->where('can.decide', false));
    });
});

describe('the list', function () {
    it('says there is no forecast to go on before the first one', function () {
        $this->actingAs($this->manager)->get(route('recommendations.index'))
            ->assertInertia(fn ($page) => $page
                ->component('recommendations/index')
                ->where('forecast', null)
                ->where('recommendations.total', 0)
                ->where('counts', ['critical' => 0, 'low' => 0, 'watch' => 0, 'overstock' => 0, 'ordered' => 0]));
    });

    it('counts the products at each level', function () {
        adviceForFive();

        $this->actingAs($this->manager)->get(route('recommendations.index'))
            ->assertInertia(fn ($page) => $page->where('counts', ['critical' => 1, 'low' => 1, 'watch' => 1, 'overstock' => 1, 'ordered' => 0]));
    });

    it('lists what needs a decision, most urgent first', function () {
        adviceForFive();

        $this->actingAs($this->manager)->get(route('recommendations.index'))
            ->assertInertia(fn ($page) => $page
                ->where('filters.view', 'todo')
                ->where('recommendations.total', 3)
                ->where('recommendations.data', fn ($rows) => $rows->pluck('product.name')->all() === ['Nails', 'Cement', 'Paint']
                    && $rows->pluck('risk')->all() === ['critical', 'low', 'watch']));
    });

    it('gives each row the figures and the reasoning', function () {
        adviceForFive();

        $this->actingAs($this->manager)->get(route('recommendations.index'))
            ->assertInertia(fn ($page) => $page
                ->where('recommendations.data.0.product', ['id' => Product::where('sku', 'HW-1')->value('id'), 'sku' => 'HW-1', 'name' => 'Nails', 'category' => 'Hardware', 'unit' => 'pc', 'moq' => 1, 'pack_size' => 1])
                ->where('recommendations.data.0.risk_label', 'Critical')
                ->where('recommendations.data.0.status', 'pending')
                ->where('recommendations.data.0.is_pending', true)
                ->where('recommendations.data.0.on_hand', 10)
                ->where('recommendations.data.0.on_order', 0)
                ->where('recommendations.data.0.lead_time_days', 7)
                ->where('recommendations.data.0.lead_time_demand', 70)
                ->where('recommendations.data.0.safety_stock', 0)
                ->where('recommendations.data.0.reorder_point', 70)
                ->where('recommendations.data.0.order_up_to', 140)
                ->where('recommendations.data.0.recommended_qty', 130)
                ->where('recommendations.data.0.order_by_date', '2026-10-05')
                ->where('recommendations.data.0.explanation', fn ($text) => str_contains($text, 'Order 130 now'))
                ->where('recommendations.data.0.final_qty', null)
                ->where('recommendations.data.0.decided_by', null));
    });

    it('shows overstock as information, in its own view', function () {
        adviceForFive();

        $this->actingAs($this->manager)->get(route('recommendations.index', ['view' => 'overstock']))
            ->assertInertia(fn ($page) => $page
                ->where('recommendations.total', 1)
                ->where('recommendations.data.0.product.name', 'Rice')
                ->where('recommendations.data.0.status', 'info')
                ->where('recommendations.data.0.is_pending', false));
    });

    it('narrows to one risk level', function () {
        adviceForFive();

        $this->actingAs($this->manager)->get(route('recommendations.index', ['risk' => 'low']))
            ->assertInertia(fn ($page) => $page->where('recommendations.total', 1)->where('recommendations.data.0.product.name', 'Cement')->where('filters.risk', 'low'));
    });

    it('narrows to a category and to a search', function () {
        adviceForFive();

        $this->actingAs($this->manager)->get(route('recommendations.index', ['category' => $this->hardware->id, 'search' => 'nail']))
            ->assertInertia(fn ($page) => $page->where('recommendations.total', 1)->where('recommendations.data.0.product.name', 'Nails'));

        $this->actingAs($this->manager)->get(route('recommendations.index', ['category' => $this->food->id]))
            ->assertInertia(fn ($page) => $page->where('recommendations.total', 0));
    });

    it('ignores a risk or view it does not know', function () {
        adviceForFive();

        $this->actingAs($this->manager)->get(route('recommendations.index', ['risk' => 'nonsense', 'view' => 'nonsense']))
            ->assertInertia(fn ($page) => $page->where('filters.risk', '')->where('filters.view', 'todo')->where('recommendations.total', 3));
    });

    it('pages through a long list', function () {
        adviceForFive();

        foreach (range(1, 14) as $number) {
            $product = stockedProduct("Extra {$number}", "X-{$number}", $this->hardware, 5);
            forecastFor(ForecastRun::latest('id')->first(), $product, [['2026-10-05', 70, 56, 84]]);
        }

        app(RecommendationGenerator::class)->generate(testToday());

        $this->actingAs($this->manager)->get(route('recommendations.index'))
            ->assertInertia(fn ($page) => $page->has('recommendations.data', 15)->where('recommendations.total', 17)->where('recommendations.last_page', 2));
    });

    it('shows the forecast the advice is built on, and whether it is getting old', function () {
        adviceForFive();

        $this->actingAs($this->manager)->get(route('recommendations.index'))
            ->assertInertia(fn ($page) => $page
                ->where('forecast.granularity', 'week')
                ->where('forecast.age_days', 4)    // finished on 1 October, today the 5th
                ->where('forecast.stale', false));

        ForecastRun::query()->update(['finished_at' => testToday()->subDays(20)]);

        $this->actingAs($this->manager)->get(route('recommendations.index'))
            ->assertInertia(fn ($page) => $page->where('forecast.age_days', 20)->where('forecast.stale', true));
    });
});

describe('decisions', function () {
    beforeEach(function () {
        adviceForFive();
        $this->nailsRecommendation = Recommendation::open()->whereHas('product', fn ($q) => $q->where('sku', 'HW-1'))->firstOrFail();
        $this->nails = $this->nailsRecommendation->product;
    });

    it('accepts, counts the quantity as on order, and says so', function () {
        $this->actingAs($this->manager)
            ->post(route('recommendations.accept', $this->nailsRecommendation), ['note' => 'Phoned the supplier'])
            ->assertRedirect()
            ->assertSessionHas('inertia.flash_data.toast.type', 'success');

        $decided = $this->nailsRecommendation->fresh();

        expect($decided->status)->toBe(RecommendationStatus::Accepted)
            ->and($decided->final_qty)->toBe(130)
            ->and($decided->decided_by)->toBe($this->manager->id)
            ->and($decided->note)->toBe('Phoned the supplier')
            ->and(InventoryLevel::where('product_id', $this->nails->id)->value('on_order'))->toBe(130);
    });

    it('accepts with a different quantity', function () {
        $this->actingAs($this->manager)->post(route('recommendations.adjust', $this->nailsRecommendation), ['quantity' => 200, 'note' => 'Bulk price'])->assertSessionHasNoErrors();

        expect($this->nailsRecommendation->fresh())
            ->status->toBe(RecommendationStatus::Adjusted)
            ->final_qty->toBe(200)
            ->recommended_qty->toBe(130);
    });

    it('refuses a quantity that is missing or makes no sense', function (mixed $quantity) {
        $this->actingAs($this->manager)
            ->post(route('recommendations.adjust', $this->nailsRecommendation), ['quantity' => $quantity])
            ->assertSessionHasErrors('quantity');

        expect($this->nailsRecommendation->fresh()->status)->toBe(RecommendationStatus::Pending);
    })->with([null, 0, -4, 'lots', 2.5, 10_000_001]);

    it('dismisses, and says how long the product is left alone', function () {
        $this->actingAs($this->manager)
            ->post(route('recommendations.dismiss', $this->nailsRecommendation), ['note' => 'Discontinuing it'])
            ->assertSessionHas('inertia.flash_data.toast.message', 'Dismissed. This product will not be recommended again for 7 days.');

        expect($this->nailsRecommendation->fresh())
            ->status->toBe(RecommendationStatus::Dismissed)
            ->snoozed_until->toDateString()->toBe('2026-10-12');
    });

    it('cancels an accepted order', function () {
        $this->actingAs($this->manager)->post(route('recommendations.accept', $this->nailsRecommendation));

        $this->actingAs($this->manager)
            ->post(route('recommendations.cancel', $this->nailsRecommendation), ['note' => 'Supplier cannot deliver'])
            ->assertSessionHas('inertia.flash_data.toast.type', 'success');

        expect($this->nailsRecommendation->fresh()->status)->toBe(RecommendationStatus::Cancelled)
            ->and(InventoryLevel::where('product_id', $this->nails->id)->value('on_order'))->toBe(0);
    });

    it('says when it was already dealt with, instead of ordering twice', function () {
        $this->actingAs($this->manager)->post(route('recommendations.accept', $this->nailsRecommendation));

        $this->actingAs($this->manager)
            ->post(route('recommendations.accept', $this->nailsRecommendation))
            ->assertSessionHasErrors(['recommendation' => 'This recommendation has already been dealt with or is no longer needed.']);

        expect(InventoryLevel::where('product_id', $this->nails->id)->value('on_order'))->toBe(130);
    });

    it('refuses to cancel what was never accepted', function () {
        $this->actingAs($this->manager)->post(route('recommendations.cancel', $this->nailsRecommendation))->assertSessionHasErrors('recommendation');
    });

    it('limits the length of a note', function () {
        $this->actingAs($this->manager)->post(route('recommendations.accept', $this->nailsRecommendation), ['note' => str_repeat('x', 501)])->assertSessionHasErrors('note');
    });

    it('does not find a recommendation that does not exist', function (string $action) {
        $this->actingAs($this->manager)->post(route("recommendations.{$action}", 999999), ['quantity' => 5])->assertNotFound();
    })->with(['accept', 'adjust', 'dismiss', 'cancel']);

    it('moves the decision to the decided view, with who made it', function () {
        $this->actingAs($this->manager)->post(route('recommendations.accept', $this->nailsRecommendation), ['note' => 'Ordered']);

        $this->actingAs($this->manager)->get(route('recommendations.index', ['view' => 'decided']))
            ->assertInertia(fn ($page) => $page
                ->where('recommendations.total', 1)
                ->where('recommendations.data.0.status', 'accepted')
                ->where('recommendations.data.0.final_qty', 130)
                ->where('recommendations.data.0.decided_by', 'Pat Manager')
                ->where('recommendations.data.0.note', 'Ordered')
                ->where('recommendations.data.0.is_ordered', true)
                ->where('counts.ordered', 1));

        $this->actingAs($this->manager)->get(route('recommendations.index'))
            ->assertInertia(fn ($page) => $page->where('recommendations.total', 2));
    });

    it('lists the newest decision first, whatever it was', function () {
        $cement = Recommendation::open()->whereHas('product', fn ($q) => $q->where('sku', 'HW-2'))->firstOrFail();

        $this->actingAs($this->manager)->post(route('recommendations.accept', $this->nailsRecommendation));
        $this->travelTo(now()->addHour());
        $this->actingAs($this->manager)->post(route('recommendations.dismiss', $cement));

        $this->actingAs($this->manager)->get(route('recommendations.index', ['view' => 'decided']))
            ->assertInertia(fn ($page) => $page->where('recommendations.data', fn ($rows) => $rows->pluck('status')->all() === ['dismissed', 'accepted']));
    });
});

describe('refreshing', function () {
    it('queues a recalculation', function () {
        Queue::fake();

        $this->actingAs($this->manager)->post(route('recommendations.refresh'))
            ->assertRedirect()
            ->assertSessionHas('inertia.flash_data.toast.type', 'success');

        Queue::assertPushed(GenerateRecommendationsJob::class);
    });
});
