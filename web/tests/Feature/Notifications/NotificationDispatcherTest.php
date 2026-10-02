<?php

use App\Enums\ForecastGranularity;
use App\Enums\ForecastStatus;
use App\Enums\ImportStatus;
use App\Enums\ImportType;
use App\Models\Category;
use App\Models\ForecastRun;
use App\Models\ImportBatch;
use App\Models\Product;
use App\Models\Recommendation;
use App\Models\User;
use App\Notifications\CriticalStockNotification;
use App\Notifications\ForecastRunNotification;
use App\Notifications\ImportErrorsNotification;
use App\Notifications\ReplenishmentDigestNotification;
use App\Services\Notifications\NotificationDispatcher;
use App\Services\Notifications\NotificationPreferences;
use App\Services\Replenishment\RecommendationGenerator;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Notification;

beforeEach(function () {
    $this->seed(RolesAndPermissionsSeeder::class);
    Notification::fake();
    Cache::flush();
    $this->travelTo(testToday()->setTime(7, 0));

    $this->owner = User::factory()->owner()->create();
    $this->manager = User::factory()->manager()->create();
    $this->staff = User::factory()->inventoryStaff()->create();
    $this->category = Category::factory()->create(['service_level' => 95]);
    $this->dispatcher = app(NotificationDispatcher::class);
});

/**
 * Open critical recommendations for the given products: no stock, a forecast of 70 a week.
 *
 * @param  list<Product>  $products
 */
function criticalFor(array $products): Collection
{
    $perWeek = [];

    foreach ($products as $product) {
        putInStock($product, 10);
        $perWeek[$product->id] = 70;
    }

    forecastWith($perWeek);

    return app(RecommendationGenerator::class)->generate(testToday())->critical;
}

function newProduct(string $name, string $sku): Product
{
    return Product::factory()->for(test()->category)->create(['name' => $name, 'sku' => $sku, 'lead_time_days' => 7]);
}

describe('critical stock', function () {
    it('tells the Owner and Manager, not inventory staff', function () {
        $critical = criticalFor([newProduct('Nails', 'HW-1')]);

        expect($this->dispatcher->criticalStock($critical))->toBe(1);

        Notification::assertSentTo([$this->owner, $this->manager], CriticalStockNotification::class);
        Notification::assertNotSentTo($this->staff, CriticalStockNotification::class);
    });

    it('does not tell someone whose account is switched off', function () {
        $inactive = User::factory()->manager()->inactive()->create();

        $this->dispatcher->criticalStock(criticalFor([newProduct('Nails', 'HW-1')]));

        Notification::assertNotSentTo($inactive, CriticalStockNotification::class);
    });

    it('lists the products, with what is available and what is expected to sell', function () {
        $this->dispatcher->criticalStock(criticalFor([newProduct('Nails', 'HW-1'), newProduct('Cement', 'HW-2')]));

        Notification::assertSentTo($this->owner, CriticalStockNotification::class, function (CriticalStockNotification $notification) {
            $mail = implode("\n", array_map('strval', $notification->toMail($this->owner)->introLines));

            return str_contains($mail, 'Nails (HW-1): 10 available, 70 expected to sell over the lead time.')
                && str_contains($mail, 'Cement (HW-2): 10 available, 70 expected to sell over the lead time.')
                && $notification->toArray($this->owner)['title'] === 'Critical stock: 2 products may run out';
        });
    });

    it('counts what is on order as available', function () {
        $product = newProduct('Nails', 'HW-1');
        $critical = criticalFor([$product]);
        $critical->first()->update(['on_order' => 5]);

        $this->dispatcher->criticalStock(Recommendation::all());

        Notification::assertSentTo($this->owner, CriticalStockNotification::class, fn ($n) => str_contains(implode("\n", array_map('strval', $n->toMail($this->owner)->introLines)), '15 available'));
    });

    it('reports a product once a day, however many times it is critical', function () {
        $critical = criticalFor([newProduct('Nails', 'HW-1')]);

        expect($this->dispatcher->criticalStock($critical))->toBe(1)
            ->and($this->dispatcher->criticalStock($critical))->toBe(0)
            ->and($this->dispatcher->criticalStock($critical))->toBe(0);

        Notification::assertSentToTimes($this->owner, CriticalStockNotification::class, 1);
    });

    it('reports it again the next day if it is still critical', function () {
        $critical = criticalFor([newProduct('Nails', 'HW-1')]);
        $this->dispatcher->criticalStock($critical);

        $this->travelTo(testToday()->addDay()->setTime(7, 0));

        expect($this->dispatcher->criticalStock($critical))->toBe(1);
        Notification::assertSentToTimes($this->owner, CriticalStockNotification::class, 2);
    });

    it('leaves out products already reported today and tells about the rest', function () {
        $nails = newProduct('Nails', 'HW-1');
        $cement = newProduct('Cement', 'HW-2');
        $critical = criticalFor([$nails, $cement]);
        $this->dispatcher->criticalStock($critical->where('product_id', $nails->id));

        expect($this->dispatcher->criticalStock($critical))->toBe(1);

        Notification::assertSentTo($this->owner, CriticalStockNotification::class, fn ($n) => str_contains($n->toArray($this->owner)['message'], 'Cement') && ! str_contains($n->toArray($this->owner)['message'], 'Nails'));
    });

    it('says nothing when nothing is critical', function () {
        expect($this->dispatcher->criticalStock(new Collection))->toBe(0);

        Notification::assertNothingSent();
    });

    it('says nothing when there is nobody to tell', function () {
        User::query()->whereKey([$this->owner->id, $this->manager->id])->update(['is_active' => false]);

        expect($this->dispatcher->criticalStock(criticalFor([newProduct('Nails', 'HW-1')])))->toBe(0);
    });

    it('is sent as one message for the round, not one per product', function () {
        $this->dispatcher->criticalStock(criticalFor([newProduct('A', 'A-1'), newProduct('B', 'B-1'), newProduct('C', 'C-1')]));

        Notification::assertSentToTimes($this->owner, CriticalStockNotification::class, 1);
    });
});

describe('the digest', function () {
    function digestSetup(): void
    {
        $nails = newProduct('Nails', 'HW-1');
        $cement = newProduct('Cement', 'HW-2');
        $paint = newProduct('Paint', 'HW-3');
        $surplus = newProduct('Surplus', 'HW-4');
        putInStock($nails, 10);      // critical
        putInStock($cement, 70);     // low
        putInStock($paint, 100);     // watch
        putInStock($surplus, 9000);  // overstocked
        forecastWith([$nails->id => 70, $cement->id => 70, $paint->id => 70, $surplus->id => 70]);
        app(RecommendationGenerator::class)->generate(testToday());
    }

    it('goes to those who want it weekly, by default, on Mondays', function () {
        digestSetup();

        expect($this->dispatcher->digest(['weekly']))->toBe(2);

        Notification::assertSentTo([$this->owner, $this->manager], ReplenishmentDigestNotification::class);
        Notification::assertNotSentTo($this->staff, ReplenishmentDigestNotification::class);
    });

    it('goes only to those whose frequency is among the ones asked for', function () {
        digestSetup();
        app(NotificationPreferences::class)->update($this->manager, ['replenishment_digest' => ['mail' => true, 'database' => true, 'digest' => 'daily']]);

        expect($this->dispatcher->digest(['daily']))->toBe(1);

        Notification::assertSentTo($this->manager, ReplenishmentDigestNotification::class);
        Notification::assertNotSentTo($this->owner, ReplenishmentDigestNotification::class);
    });

    it('does not go to someone who turned it off', function () {
        digestSetup();
        app(NotificationPreferences::class)->update($this->manager, ['replenishment_digest' => ['mail' => true, 'database' => true, 'digest' => 'off']]);

        $this->dispatcher->digest(['daily', 'weekly']);

        Notification::assertNotSentTo($this->manager, ReplenishmentDigestNotification::class);
        Notification::assertSentTo($this->owner, ReplenishmentDigestNotification::class);
    });

    it('counts the products at each level and lists the most urgent first', function () {
        digestSetup();

        $this->dispatcher->digest(['weekly']);

        Notification::assertSentTo($this->owner, ReplenishmentDigestNotification::class, function (ReplenishmentDigestNotification $digest) {
            $lines = array_map('strval', $digest->toMail($this->owner)->introLines);

            return $digest->toArray($this->owner)['title'] === 'Replenishment digest: 3 products to order'
                && str_contains($lines[0], '1 critical')
                && str_contains($lines[0], '1 low')
                && str_contains($lines[0], '1 to watch')
                && $lines[1] === 'The most urgent:'
                && str_starts_with($lines[2], 'Nails (HW-1), Critical: order 130 now.')
                && str_starts_with($lines[3], 'Cement (HW-2), Low: order')
                && str_starts_with($lines[4], 'Paint (HW-3), Watch: order 70 by Oct 8.')
                && in_array('1 product has more stock than needed.', $lines, true);
        });
    });

    it('lists at most five items', function () {
        $products = [];
        foreach (range(1, 8) as $number) {
            $products[] = newProduct("Item {$number}", "I-{$number}");
        }
        criticalFor($products);

        $this->dispatcher->digest(['weekly']);

        Notification::assertSentTo($this->owner, ReplenishmentDigestNotification::class, function ($digest) {
            $lines = array_map('strval', $digest->toMail($this->owner)->introLines);

            return count(array_filter($lines, fn ($line) => str_contains($line, ', Critical: order'))) === 5
                && str_contains($lines[0], '8 critical');
        });
    });

    it('is not sent when nothing needs ordering', function () {
        $fine = newProduct('Fine', 'F-1');
        putInStock($fine, 300);
        forecastWith([$fine->id => 70]);
        app(RecommendationGenerator::class)->generate(testToday());

        expect($this->dispatcher->digest(['daily', 'weekly']))->toBe(0);

        Notification::assertNothingSent();
    });

    it('is not sent when the only news is overstock', function () {
        $surplus = newProduct('Surplus', 'S-1');
        putInStock($surplus, 9000);
        forecastWith([$surplus->id => 70]);
        app(RecommendationGenerator::class)->generate(testToday());

        expect($this->dispatcher->digest(['weekly']))->toBe(0);
    });

    it('is not about advice that has been dealt with', function () {
        digestSetup();
        Recommendation::query()->update(['status' => 'dismissed']);

        expect($this->dispatcher->digest(['weekly']))->toBe(0);
    });

    it('warns when the forecast behind it is old', function () {
        criticalFor([newProduct('Nails', 'HW-1')]);
        ForecastRun::query()->update(['finished_at' => testToday()->subDays(30)]);

        $this->dispatcher->digest(['weekly']);

        Notification::assertSentTo($this->owner, ReplenishmentDigestNotification::class, fn ($digest) => in_array('The forecast behind these figures is 30 days old. A fresh one would make them more reliable.', array_map('strval', $digest->toMail($this->owner)->introLines), true));
    });

    it('says nothing of the forecast while it is fresh', function () {
        criticalFor([newProduct('Nails', 'HW-1')]);

        $this->dispatcher->digest(['weekly']);

        Notification::assertSentTo($this->owner, ReplenishmentDigestNotification::class, fn ($digest) => ! str_contains(implode("\n", array_map('strval', $digest->toMail($this->owner)->introLines)), 'old'));
    });
});

describe('a forecast run', function () {
    function runWith(array $change = []): ForecastRun
    {
        return completedRun([
            'granularity' => ForecastGranularity::Week, 'horizon' => 8,
            'metrics' => ['mae' => 1, 'rmse' => 1, 'mape' => 1, 'wape' => 16.3, 'n' => 5, 'coverage' => 70],
            'baseline_metrics' => ['seasonal_naive' => ['wape' => 19.7], 'moving_average' => ['wape' => 18.4]],
            ...$change,
        ]);
    }

    it('tells whoever started it and the Owner', function () {
        $this->dispatcher->forecastRun(runWith(['triggered_by' => $this->manager->id]));

        Notification::assertSentTo([$this->owner, $this->manager], ForecastRunNotification::class);
        Notification::assertNotSentTo($this->staff, ForecastRunNotification::class);
    });

    it('tells only the Owner when the schedule started it', function () {
        $this->dispatcher->forecastRun(runWith(['triggered_by' => null]));

        Notification::assertSentTo($this->owner, ForecastRunNotification::class);
        Notification::assertNotSentTo($this->manager, ForecastRunNotification::class);
    });

    it('tells the Owner once when the Owner started it', function () {
        $this->dispatcher->forecastRun(runWith(['triggered_by' => $this->owner->id]));

        Notification::assertSentToTimes($this->owner, ForecastRunNotification::class, 1);
    });

    it('does not tell someone who has left', function () {
        $left = User::factory()->manager()->inactive()->create();

        $this->dispatcher->forecastRun(runWith(['triggered_by' => $left->id]));

        Notification::assertNotSentTo($left, ForecastRunNotification::class);
        Notification::assertSentTo($this->owner, ForecastRunNotification::class);
    });

    it('gives the accuracy of a run that worked', function () {
        $this->dispatcher->forecastRun(runWith());

        Notification::assertSentTo($this->owner, ForecastRunNotification::class, function (ForecastRunNotification $notification) {
            $lines = array_map('strval', $notification->toMail($this->owner)->introLines);

            return $notification->toArray($this->owner)['title'] === 'Weekly forecast is ready'
                && $lines[0] === 'The weekly forecast finished. It looks 8 weeks ahead.'
                && $lines[1] === 'On recent weeks it was typically off by 16.3% of units sold, against 19.7% for the same week last year.';
        });
    });

    it('says why a run failed', function () {
        $this->dispatcher->forecastRun(runWith(['status' => ForecastStatus::Failed, 'error_message' => 'The forecasting service could not be reached.', 'metrics' => null]));

        Notification::assertSentTo($this->owner, ForecastRunNotification::class, function (ForecastRunNotification $notification) {
            $lines = array_map('strval', $notification->toMail($this->owner)->introLines);

            return $notification->toArray($this->owner)['title'] === 'Weekly forecast did not finish'
                && $lines === ['The weekly forecast did not finish.', 'The forecasting service could not be reached.'];
        });
    });

    it('copes with a run whose accuracy could not be measured', function () {
        $this->dispatcher->forecastRun(runWith(['metrics' => null, 'baseline_metrics' => null]));

        Notification::assertSentTo($this->owner, ForecastRunNotification::class, fn ($n) => count($n->toMail($this->owner)->introLines) === 1);
    });

    it('works for monthly runs', function () {
        $this->dispatcher->forecastRun(runWith(['granularity' => ForecastGranularity::Month, 'horizon' => 3]));

        Notification::assertSentTo($this->owner, ForecastRunNotification::class, fn ($n) => $n->toArray($this->owner)['title'] === 'Monthly forecast is ready'
            && $n->toArray($this->owner)['url'] === '/forecasts/accuracy?granularity=month');
    });
});

describe('an import', function () {
    function importWith(array $change = []): ImportBatch
    {
        return ImportBatch::create([
            'type' => ImportType::Sales, 'filename' => 'jane-doe-sales.csv', 'status' => ImportStatus::Completed,
            'settings' => ['headers' => [], 'columns' => []], 'rows_total' => 12, 'rows_ok' => 10, 'rows_failed' => 2,
            'user_id' => test()->staff->id, ...$change,
        ]);
    }

    it('tells the person who uploaded it when some rows failed', function () {
        $batch = importWith();

        $this->dispatcher->importFinished($batch);

        Notification::assertSentTo($this->staff, ImportErrorsNotification::class, function (ImportErrorsNotification $notification) use ($batch) {
            $lines = array_map('strval', $notification->toMail($this->staff)->introLines);

            return $notification->toArray($this->staff)['title'] === "Sales import #{$batch->id} had problems"
                && $notification->toArray($this->staff)['url'] === "/sales/imports/{$batch->id}"
                && $lines[0] === "Sales import #{$batch->id} finished, but 2 rows could not be imported."
                && $lines[1] === '10 rows went in.';
        });
        Notification::assertNotSentTo([$this->owner, $this->manager], ImportErrorsNotification::class);
    });

    it('says nothing about an import that went cleanly', function () {
        $this->dispatcher->importFinished(importWith(['rows_failed' => 0, 'rows_ok' => 12]));

        Notification::assertNothingSent();
    });

    it('tells the person when an import stopped part way', function () {
        $batch = importWith(['status' => ImportStatus::Failed, 'rows_failed' => 0]);

        $this->dispatcher->importFinished($batch);

        Notification::assertSentTo($this->staff, ImportErrorsNotification::class, fn ($n) => $n->toArray($this->staff)['title'] === "Sales import #{$batch->id} stopped"
            && in_array('You can undo the import and try again.', array_map('strval', $n->toMail($this->staff)->introLines), true));
    });

    it('points product imports at their own page', function () {
        $batch = importWith(['type' => ImportType::Products, 'user_id' => $this->manager->id]);

        $this->dispatcher->importFinished($batch);

        Notification::assertSentTo($this->manager, ImportErrorsNotification::class, fn ($n) => $n->toArray($this->manager)['url'] === "/products/imports/{$batch->id}"
            && str_starts_with($n->toArray($this->manager)['title'], 'Products import'));
    });

    it('does not tell anyone when there is nobody to tell', function () {
        $this->dispatcher->importFinished(importWith(['user_id' => null]));
        $this->dispatcher->importFinished(importWith(['user_id' => User::factory()->inventoryStaff()->inactive()->create()->id]));

        Notification::assertNothingSent();
    });

    it('never mentions the file\'s name, which people name after people', function () {
        $this->dispatcher->importFinished(importWith(['filename' => 'jane-doe-sales.csv']));

        Notification::assertSentTo($this->staff, ImportErrorsNotification::class, function ($notification) {
            $everything = strtolower(implode("\n", [
                ...array_map('strval', $notification->toMail($this->staff)->introLines),
                ...array_map('strval', $notification->toMail($this->staff)->outroLines),
                ...array_map('strval', $notification->toArray($this->staff)),
            ]));

            return ! str_contains($everything, 'jane') && ! str_contains($everything, 'doe') && ! str_contains($everything, '.csv');
        });
    });
});

describe('what each person chose', function () {
    it('is honoured: a notification turned off is not sent, and one by email only goes by email', function () {
        app(NotificationPreferences::class)->update($this->owner, ['critical_stock' => ['mail' => false, 'database' => false]]);
        app(NotificationPreferences::class)->update($this->manager, ['critical_stock' => ['mail' => true, 'database' => false]]);

        $this->dispatcher->criticalStock(criticalFor([newProduct('Nails', 'HW-1')]));

        Notification::assertSentTo($this->manager, CriticalStockNotification::class, fn ($n, array $channels) => $channels === ['mail']);
        // The Owner turned both channels off, so there is nothing left to deliver it on.
        Notification::assertNotSentTo($this->owner, CriticalStockNotification::class);
    });

    it('is the default: both channels', function () {
        $this->dispatcher->criticalStock(criticalFor([newProduct('Nails', 'HW-1')]));

        Notification::assertSentTo($this->owner, CriticalStockNotification::class, fn ($n, array $channels) => $channels === ['mail', 'database']);
    });
});
