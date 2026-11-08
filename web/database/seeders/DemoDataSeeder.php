<?php

namespace Database\Seeders;

use App\Enums\ImportStatus;
use App\Enums\ImportType;
use App\Enums\StockMovementType;
use App\Models\Category;
use App\Models\ImportBatch;
use App\Models\Product;
use App\Models\User;
use App\Services\Imports\ImportManager;
use App\Services\Imports\ImportProcessor;
use App\Services\Inventory\StockService;
use Carbon\CarbonImmutable;
use Generator;
use Illuminate\Database\Seeder;
use Illuminate\Http\UploadedFile;
use RuntimeException;
use SplFileObject;

/**
 * Loads a realistic shop for demonstrations and development: five categories,
 * fifty products, and two years of daily sales and deliveries, from the CSV
 * files written by ml/scripts/generate_synthetic.py.
 *
 * The sales go in through the same import used for real uploads (reading,
 * checking, skipping repeats, recording sales and taking stock off), so
 * seeding doubles as a test of it. The result is a stock ledger that adds up
 * to today's stock levels, with a handful of products low or out of stock.
 *
 * Not run in production. Does nothing if products already exist, so running it
 * twice cannot double the data.
 */
class DemoDataSeeder extends Seeder
{
    /** Days every date in the files moves forward, so the history ends in the past week. */
    private int $shift = 0;

    public function run(StockService $stock, ImportManager $imports): void
    {
        DemoGuard::refuseInProduction();

        if (Product::query()->exists()) {
            $this->say('Products already exist; leaving the demo data alone.', 'warn');

            return;
        }

        $directory = rtrim((string) config('demo.data_path'), '/\\');
        $this->shift = $this->daysToShift("{$directory}/sales.csv");

        if ($this->shift > 0) {
            $this->say(sprintf('Moving the history forward %d weeks, so it ends in the past week.', $this->shift / 7));
        }

        $this->say('Loading categories and products...');
        $this->loadCatalog($directory, $stock);

        $this->say('Loading deliveries...');
        $this->loadRestocks($directory, $stock);

        $this->say('Importing sales through the import pipeline...');
        $this->importSales($directory, $imports);
    }

    /**
     * Prints a progress line when run from the command line. There is no console
     * when a test runs the seeder, so this stays quiet then.
     */
    private function say(string $message, string $style = 'info'): void
    {
        // Laravel documents $command as always set, but it is empty outside the console.
        // @phpstan-ignore isset.property
        if (isset($this->command)) {
            $this->command->{$style}($message);
        }
    }

    private function loadCatalog(string $directory, StockService $stock): void
    {
        $categories = [];

        foreach ($this->rows("{$directory}/categories.csv") as $row) {
            $categories[$row['name']] = Category::create([
                'name' => $row['name'],
                'description' => $row['description'] ?: null,
                'service_level' => $row['service_level'],
            ]);
        }

        foreach ($this->rows("{$directory}/products.csv") as $row) {
            $product = Product::create([
                'sku' => $row['sku'],
                'name' => $row['name'],
                'category_id' => $categories[$row['category']]->id,
                'unit' => $row['unit'],
                'unit_cost' => $row['unit_cost'],
                'unit_price' => $row['unit_price'],
                'lead_time_days' => (int) $row['lead_time_days'],
                'moq' => (int) $row['moq'],
                'pack_size' => (int) $row['pack_size'],
                'reorder_point_override' => $row['reorder_point'] === '' ? null : (int) $row['reorder_point'],
            ]);

            // Stocked on the day the product first went on sale (not today), so
            // the ledger tells the same story as the sales history.
            $stock->openingStock(
                $product,
                (int) $row['opening_stock'],
                occurredAt: $this->date($row['start_date'])->setTime(8, 0),
            );
        }
    }

    private function loadRestocks(string $directory, StockService $stock): void
    {
        $products = Product::query()->get()->keyBy('sku');
        $entries = [];

        foreach ($this->rows("{$directory}/restocks.csv") as $row) {
            $entries[] = [
                'product_id' => $products[$row['sku']]->id,
                'type' => StockMovementType::Restock,
                'quantity' => (int) $row['quantity'],
                'occurred_at' => $this->date($row['date'])->setTime(9, 0),
                'note' => 'Delivery',
            ];
        }

        $stock->recordMany($entries);
    }

    private function importSales(string $directory, ImportManager $imports): void
    {
        $path = $this->shiftedSales("{$directory}/sales.csv");
        $owner = User::query()->where('email', 'owner@stocksense.test')->first()
            ?? throw new RuntimeException('Run DemoUsersSeeder first: the demo owner uploads the sales file.');

        // The `true` marks it as a trusted local file rather than a browser upload.
        $file = new UploadedFile($path, 'demo-sales.csv', 'text/csv', null, true);
        $batch = $imports->start(ImportType::Sales, $file, ['adjust_stock' => true], $owner);

        $this->runToCompletion($batch, ImportType::Sales->definition()->processor());

        if ($path !== "{$directory}/sales.csv") {
            unlink($path);
        }

        $this->say(sprintf(
            '  %s sales imported (%s skipped as duplicates, %s failed).',
            number_format($batch->rows_ok),
            number_format($batch->rows_duplicate),
            number_format($batch->rows_failed),
        ));
    }

    /**
     * The files hold a fixed two years of history. Seeded months later, a shop with
     * no recent sales would show an empty dashboard, so every date moves forward by
     * the whole weeks that bring the last sale into the past week (whole weeks keep
     * each sale on its weekday and never merge two dates into one).
     *
     * ponytail: payday and seasonal peaks drift off their calendar dates by the shift;
     * regenerate the files (ml/scripts/generate_synthetic.py) when that matters.
     */
    private function daysToShift(string $salesPath): int
    {
        $last = null;
        foreach ($this->rows($salesPath) as $row) {
            $last = max($last ?? $row['date'], $row['date']);
        }

        if ($last === null) {
            return 0;
        }

        $behind = CarbonImmutable::parse($last)->diffInDays(CarbonImmutable::yesterday(), false);

        return max(0, intdiv((int) $behind, 7) * 7);
    }

    private function date(string $value): CarbonImmutable
    {
        return CarbonImmutable::parse($value)->addDays($this->shift);
    }

    /** The sales file with its dates moved, written beside the other temporary files. */
    private function shiftedSales(string $path): string
    {
        if ($this->shift === 0) {
            return $path;
        }

        $out = new SplFileObject(tempnam(sys_get_temp_dir(), 'demo-sales'), 'w');
        $out->fputcsv(['date', 'sku', 'quantity', 'unit_price'], ',', '"', '');
        foreach ($this->rows($path) as $row) {
            $out->fputcsv([$this->date($row['date'])->toDateString(), $row['sku'], $row['quantity'], $row['unit_price']], ',', '"', '');
        }

        return $out->getPathname();
    }

    /**
     * What the queue does for a real upload, done here in one go: work through
     * the file a slice at a time until it is finished.
     */
    private function runToCompletion(ImportBatch $batch, ImportProcessor $processor): void
    {
        $batch->forceFill(['status' => ImportStatus::Processing, 'started_at' => now()])->save();

        $offset = 0;
        while ($processor->processNext($batch, $offset)) {
            $offset = $batch->rows_processed;
        }

        $batch->forceFill(['status' => ImportStatus::Completed, 'finished_at' => now()])->save();
    }

    /**
     * The rows of a CSV file, each keyed by its header.
     *
     * @return Generator<int, array<string, string>>
     */
    private function rows(string $path): Generator
    {
        if (! is_file($path)) {
            throw new RuntimeException("Demo data file not found: {$path}. Generate it with ml/scripts/generate_synthetic.py.");
        }

        $file = new SplFileObject($path, 'r');
        $file->setFlags(SplFileObject::READ_CSV | SplFileObject::SKIP_EMPTY | SplFileObject::READ_AHEAD | SplFileObject::DROP_NEW_LINE);
        $file->setCsvControl(',', '"', '');

        $header = null;

        foreach ($file as $cells) {
            if (! is_array($cells) || $cells === [null]) {
                continue;
            }

            if ($header === null) {
                $header = array_map('strval', $cells);

                continue;
            }

            yield array_combine($header, array_map('strval', $cells));
        }
    }
}
