<?php

use App\Enums\ForecastGranularity;
use App\Enums\ForecastStatus;
use App\Enums\ImportStatus;
use App\Jobs\GenerateRecommendationsJob;
use App\Jobs\ProcessImportChunk;
use App\Jobs\RunForecastJob;
use App\Models\Category;
use App\Models\ImportBatch;
use App\Models\Product;
use App\Models\Recommendation;
use App\Models\User;
use App\Notifications\CriticalStockNotification;
use App\Notifications\ForecastRunNotification;
use App\Notifications\ImportErrorsNotification;
use App\Services\Forecasting\ForecastRunner;
use App\Services\Notifications\NotificationDispatcher;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Storage;

beforeEach(function () {
    $this->seed(RolesAndPermissionsSeeder::class);
    Notification::fake();
    Cache::flush();
    config(['forecasting.ml.url' => 'http://ml.test:8000', 'forecasting.ml.token' => 'secret']);

    $this->owner = User::factory()->owner()->create();
    $this->manager = User::factory()->manager()->create();
});

describe('a forecast run', function () {
    it('tells the people who should know when it works, and asks for fresh reorder advice', function () {
        Bus::fake([GenerateRecommendationsJob::class]);
        productsFromTheExample();
        Http::fake(['ml.test:8000/*' => Http::response(exampleAnswer())]);
        $run = app(ForecastRunner::class)->start(ForecastGranularity::Week, $this->manager, 4, queue: false);

        RunForecastJob::dispatchSync($run->id);

        Notification::assertSentTo([$this->owner, $this->manager], ForecastRunNotification::class, fn ($n) => $n->toArray($this->owner)['title'] === 'Weekly forecast is ready');
        Bus::assertDispatched(GenerateRecommendationsJob::class);
    });

    it('tells them when it fails, and does not ask for new advice', function () {
        Bus::fake([GenerateRecommendationsJob::class]);
        Http::fake();
        $run = app(ForecastRunner::class)->start(ForecastGranularity::Week, $this->manager, 4, queue: false);

        RunForecastJob::dispatchSync($run->id);

        Notification::assertSentTo([$this->owner, $this->manager], ForecastRunNotification::class, fn ($n) => $n->toArray($this->owner)['title'] === 'Weekly forecast did not finish'
            && str_contains($n->toArray($this->owner)['message'], 'no sales history'));
        Bus::assertNotDispatched(GenerateRecommendationsJob::class);
    });

    it('tells them when something unexpected stops it, without the detail', function () {
        $run = app(ForecastRunner::class)->start(ForecastGranularity::Week, $this->manager, 4, queue: false);

        (new RunForecastJob($run->id))->failed(new RuntimeException('SQLSTATE[HY000]: something internal'));

        Notification::assertSentTo($this->owner, ForecastRunNotification::class, fn ($n) => str_contains($n->toArray($this->owner)['message'], 'unexpected error')
            && ! str_contains(implode("\n", array_map('strval', $n->toArray($this->owner))), 'SQLSTATE'));
    });

    it('is not undone by a notification that cannot be sent', function () {
        Bus::fake([GenerateRecommendationsJob::class]);
        productsFromTheExample();
        Http::fake(['ml.test:8000/*' => Http::response(exampleAnswer())]);
        $this->mock(NotificationDispatcher::class)->shouldReceive('forecastRun')->andThrow(new RuntimeException('mail server is down'));
        $run = app(ForecastRunner::class)->start(ForecastGranularity::Week, $this->manager, 4, queue: false);

        RunForecastJob::dispatchSync($run->id);

        expect($run->fresh()->status)->toBe(ForecastStatus::Completed);
        Bus::assertDispatched(GenerateRecommendationsJob::class);
    });
});

describe('the reorder advice job', function () {
    it('works out the advice and tells the deciders about critical products', function () {
        $this->travelTo(testToday()->setTime(6, 0));
        $category = Category::factory()->create();
        $product = Product::factory()->for($category)->create(['name' => 'Nails', 'lead_time_days' => 7]);
        putInStock($product, 10);
        forecastWith([$product->id => 70]);

        GenerateRecommendationsJob::dispatchSync();

        expect(Recommendation::open()->count())->toBe(1);
        Notification::assertSentTo([$this->owner, $this->manager], CriticalStockNotification::class);
    });

    it('does nothing, quietly, before there is a forecast', function () {
        GenerateRecommendationsJob::dispatchSync();

        expect(Recommendation::count())->toBe(0);
        Notification::assertNothingSent();
    });
});

describe('an import', function () {
    beforeEach(function () {
        Storage::fake('local');
        $this->product = Product::factory()->create(['sku' => 'HW-001']);
        $this->uploader = User::factory()->inventoryStaff()->create();
    });

    /** Uploads and confirms a sales file with the given rows (the test queue runs it straight away). */
    function importRows(array $rows): ImportBatch
    {
        $csv = "date,sku,quantity,unit_price\n".implode("\n", array_map(fn ($row) => implode(',', $row), $rows));
        test()->actingAs(test()->uploader)
            ->post(route('sales.imports.store'), ['file' => UploadedFile::fake()->createWithContent('sales.csv', $csv), 'adjust_stock' => false])
            ->assertSessionHasNoErrors();

        $batch = ImportBatch::latest('id')->firstOrFail();
        test()->actingAs(test()->uploader)->post(route('sales.imports.confirm', $batch))->assertSessionHasNoErrors();

        return $batch->fresh();
    }

    it('tells the person who uploaded it when rows failed', function () {
        $batch = importRows([['2026-03-05', 'HW-001', 5, '10'], ['2026-03-05', 'NOPE', 1, '10']]);

        expect($batch->status)->toBe(ImportStatus::Completed);

        Notification::assertSentTo($this->uploader, ImportErrorsNotification::class, fn ($n) => $n->toArray($this->uploader)['title'] === "Sales import #{$batch->id} had problems"
            && $n->toArray($this->uploader)['message'] === '1 row could not be imported.');
    });

    it('says nothing about a clean import', function () {
        importRows([['2026-03-05', 'HW-001', 5, '10']]);

        Notification::assertNothingSent();
    });

    it('tells the person when an import stops part way', function () {
        $batch = importRows([['2026-03-05', 'HW-001', 5, '10']]);
        $batch->forceFill(['status' => ImportStatus::Processing])->save();
        Notification::fake();

        (new ProcessImportChunk($batch->id, 0))->failed(new RuntimeException('boom'));

        Notification::assertSentTo($this->uploader, ImportErrorsNotification::class, fn ($n) => str_ends_with($n->toArray($this->uploader)['title'], 'stopped'));
        expect($batch->fresh()->status)->toBe(ImportStatus::Failed);
    });

    it('is not undone by a notification that cannot be sent', function () {
        $this->mock(NotificationDispatcher::class)->shouldReceive('importFinished')->andThrow(new RuntimeException('mail server is down'));

        $batch = importRows([['2026-03-05', 'HW-001', 5, '10'], ['2026-03-05', 'NOPE', 1, '10']]);

        expect($batch->status)->toBe(ImportStatus::Completed)
            ->and($batch->rows_ok)->toBe(1);
    });
});
