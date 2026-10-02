<?php

namespace App\Http\Controllers;

use App\Enums\ForecastGranularity;
use App\Enums\ForecastStatus;
use App\Models\Category;
use App\Models\Forecast;
use App\Models\ForecastRun;
use App\Models\Product;
use App\Models\Sale;
use App\Services\Forecasting\ForecastRunner;
use App\Services\Forecasting\SeriesBuilder;
use App\Services\Inventory\LocationContext;
use Carbon\CarbonImmutable;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Sales forecasts: what is expected to sell, how sure the model is, and how
 * accurate it has been. Looking needs `forecasts.view`, starting a run needs
 * `forecasts.run` (see routes/web.php).
 *
 * @phpstan-type ProductRow array{id: int, sku: string, name: string, category: string, unit: string, next: float, next_lower: float, next_upper: float, total: float, recent: int, change: float|null, low_confidence: bool}
 */
class ForecastController extends Controller
{
    public function __construct(
        private readonly ForecastRunner $runner,
        private readonly LocationContext $locations,
        private readonly SeriesBuilder $history,
    ) {}

    /**
     * Every forecast product with what is expected over the horizon.
     */
    public function index(Request $request): Response
    {
        $granularity = $this->granularity($request);
        $run = ForecastRun::latestCompleted($granularity);

        $filters = [
            'search' => (string) $request->query('search', ''),
            'category' => $request->query('category') !== null ? (int) $request->query('category') : null,
            'confidence' => $request->query('confidence') === 'low' ? 'low' : '',
            'sort' => $request->query('sort') === 'forecast' ? 'forecast' : 'name',
        ];

        return Inertia::render('forecasts/index', [
            'granularity' => $granularity->value,
            'granularities' => $this->granularityOptions(),
            'run' => $run === null ? null : $this->summary($run),
            'activeRun' => $this->activeRun($granularity),
            'failure' => $this->failure($granularity, $run),
            'products' => $run === null ? null : $this->products($run, $filters),
            'periods' => $run === null ? [] : $this->forecastPeriods($run),
            'filters' => $filters,
            'categories' => Category::query()->orderBy('name')->get(['id', 'name']),
            'can' => ['run' => (bool) $request->user()?->can('forecasts.run')],
        ]);
    }

    /**
     * One product: its recent sales and what is expected next, with the range.
     */
    public function product(Request $request, Product $product): Response
    {
        $granularity = $this->granularity($request);
        $run = ForecastRun::latestCompleted($granularity);
        $location = $this->locations->current();

        $forecasts = $run === null ? collect() : Forecast::query()
            ->where('forecast_run_id', $run->id)
            ->where('product_id', $product->id)
            ->orderBy('period_start')
            ->get();

        $history = $run === null || $run->as_of === null ? [] : $this->history->history(
            $product,
            $location,
            $granularity,
            CarbonImmutable::instance($run->as_of),
            $granularity->historyPeriods(),
        );

        $first = $forecasts->first();

        return Inertia::render('forecasts/product', [
            'product' => [
                'id' => $product->id,
                'sku' => $product->sku,
                'name' => $product->name,
                'category' => $product->category->name,
                'unit' => $product->unit,
                'is_active' => $product->is_active,
            ],
            'granularity' => $granularity->value,
            'granularities' => $this->granularityOptions(),
            'run' => $run === null ? null : $this->summary($run),
            'history' => $history,
            'forecast' => $forecasts->map(fn (Forecast $forecast) => [
                'period' => $forecast->period_start->toDateString(),
                'yhat' => (float) $forecast->yhat,
                'lower' => (float) $forecast->yhat_lower,
                'upper' => (float) $forecast->yhat_upper,
            ])->values()->all(),
            'method' => $first?->method->value,
            'method_label' => $first?->method->label(),
            'low_confidence' => (bool) $first?->low_confidence,
            'typical_error' => $run?->residual_std[$product->id] ?? null,
            'history_periods' => $this->historyLength($product, $granularity, $run),
        ]);
    }

    /**
     * Starts a forecast run (or says one is already going).
     */
    public function run(Request $request): RedirectResponse
    {
        $validated = $request->validate([
            'granularity' => ['required', Rule::enum(ForecastGranularity::class)],
            'horizon' => ['nullable', 'integer', 'min:1', 'max:26'],
        ]);

        $granularity = ForecastGranularity::from($validated['granularity']);
        $horizon = isset($validated['horizon']) ? (int) $validated['horizon'] : null;

        if ($horizon !== null && $horizon > $granularity->maxHorizon()) {
            return back()->withErrors(['horizon' => "A {$granularity->noun()}ly forecast can look at most {$granularity->maxHorizon()} {$granularity->noun()}s ahead."]);
        }

        $run = $this->runner->start($granularity, $request->user(), $horizon);

        Inertia::flash('toast', $run->wasRecentlyCreated
            ? ['type' => 'success', 'message' => 'Forecast started. It usually takes a minute or two.']
            : ['type' => 'info', 'message' => "A {$granularity->noun()}ly forecast is already running."]);

        return to_route('forecasts.index', ['granularity' => $granularity->value]);
    }

    /**
     * How accurate the forecasts have been: the model against the two simple
     * yardsticks, by category, run by run.
     */
    public function accuracy(Request $request): Response
    {
        $granularity = $this->granularity($request);

        $requested = $request->query('run') !== null
            ? ForecastRun::query()->completed($granularity)->whereKey((int) $request->query('run'))->first()
            : null;
        $run = $requested ?? ForecastRun::latestCompleted($granularity);

        $runs = ForecastRun::query()->completed($granularity)->limit(30)->get();

        return Inertia::render('forecasts/accuracy', [
            'granularity' => $granularity->value,
            'granularities' => $this->granularityOptions(),
            'run' => $run === null ? null : [
                ...$this->summary($run),
                'metrics' => $run->metrics,
                'baseline_metrics' => $run->baseline_metrics,
                'per_category' => $this->perCategory($run),
                'feature_importance' => array_slice($run->feature_importance ?? [], 0, 12),
            ],
            'runs' => $runs->map(fn (ForecastRun $past) => [
                'id' => $past->id,
                'finished_at' => $past->finished_at?->toIso8601String(),
                'as_of' => $past->as_of?->toDateString(),
                'model_version' => $past->model_version,
                'wape' => $past->metrics['wape'] ?? null,
            ])->values()->all(),
            // Oldest first, for a chart of accuracy over time.
            'trend' => $runs->filter(fn (ForecastRun $past) => $past->metrics !== null)->take(12)->reverse()->map(fn (ForecastRun $past) => [
                'run' => $past->id,
                'date' => $past->as_of?->toDateString(),
                'model' => $past->metrics['wape'] ?? null,
                'seasonal_naive' => $past->baseline_metrics['seasonal_naive']['wape'] ?? null,
                'moving_average' => $past->baseline_metrics['moving_average']['wape'] ?? null,
            ])->values()->all(),
            'can' => ['run' => (bool) $request->user()?->can('forecasts.run')],
        ]);
    }

    private function granularity(Request $request): ForecastGranularity
    {
        return ForecastGranularity::tryFrom((string) $request->query('granularity')) ?? ForecastGranularity::Week;
    }

    /**
     * @return list<array{value: string, label: string}>
     */
    private function granularityOptions(): array
    {
        return array_map(
            fn (ForecastGranularity $granularity) => ['value' => $granularity->value, 'label' => $granularity->label()],
            ForecastGranularity::cases(),
        );
    }

    /**
     * What a finished run says about itself: when, what it looked at, and how it did.
     *
     * @return array<string, mixed>
     */
    private function summary(ForecastRun $run): array
    {
        $training = $run->training ?? [];
        $first = $run->as_of === null ? null : $run->granularity->next(CarbonImmutable::instance($run->as_of));

        return [
            'id' => $run->id,
            'granularity' => $run->granularity->value,
            'horizon' => $run->horizon,
            'model_version' => $run->model_version,
            'finished_at' => $run->finished_at?->toIso8601String(),
            'as_of' => $run->as_of?->toDateString(),
            'first_period' => $first?->toDateString(),
            'last_period' => $first === null ? null : $this->addPeriods($run->granularity, $first, $run->horizon - 1)->toDateString(),
            'n_products' => $training['n_series'] ?? null,
            'n_model_products' => $training['n_model_series'] ?? null,
            'n_low_confidence' => $training['n_low_confidence'] ?? null,
            'backtest_folds' => $training['backtest_folds'] ?? null,
            'headline' => $this->headline($run),
        ];
    }

    /**
     * The one-line verdict: the model's error against each yardstick, as WAPE (% of units sold).
     *
     * @return array{model: float|null, seasonal_naive: float|null, moving_average: float|null, coverage: float|null}|null
     */
    private function headline(ForecastRun $run): ?array
    {
        if ($run->metrics === null) {
            return null;
        }

        return [
            'model' => $run->metrics['wape'] ?? null,
            'seasonal_naive' => $run->baseline_metrics['seasonal_naive']['wape'] ?? null,
            'moving_average' => $run->baseline_metrics['moving_average']['wape'] ?? null,
            'coverage' => $run->metrics['coverage'] ?? null,
        ];
    }

    /**
     * @return array<string, mixed>|null
     */
    private function activeRun(ForecastGranularity $granularity): ?array
    {
        $run = ForecastRun::query()->active()->where('granularity', $granularity->value)->latest('id')->first();

        return $run === null ? null : [
            'id' => $run->id,
            'status' => $run->status->value,
            'status_label' => $run->status->label(),
            'started_at' => ($run->started_at ?? $run->created_at)?->toIso8601String(),
        ];
    }

    /**
     * Why the latest attempt failed, if it did and nothing has succeeded since.
     */
    private function failure(ForecastGranularity $granularity, ?ForecastRun $completed): ?string
    {
        $latest = ForecastRun::query()->where('granularity', $granularity->value)->latest('id')->first();

        if ($latest === null || $latest->status !== ForecastStatus::Failed || ($completed !== null && $completed->id > $latest->id)) {
            return null;
        }

        return $latest->error_message;
    }

    /**
     * @param  array{search: string, category: int|null, confidence: string, sort: string}  $filters
     * @return LengthAwarePaginator<int, ProductRow>
     */
    private function products(ForecastRun $run, array $filters): LengthAwarePaginator
    {
        $first = $this->addPeriods($run->granularity, CarbonImmutable::instance($run->as_of ?? now()), 1);

        $totals = Forecast::query()
            ->where('forecast_run_id', $run->id)
            ->groupBy('product_id')
            ->selectRaw('product_id, sum(yhat) as total, bool_or(low_confidence) as low_confidence');

        $next = Forecast::query()
            ->where('forecast_run_id', $run->id)
            ->where('period_start', $first->toDateString())
            ->select(['product_id', 'yhat', 'yhat_lower', 'yhat_upper']);

        $page = Product::query()
            ->joinSub($totals, 'totals', 'totals.product_id', '=', 'products.id')
            ->joinSub($next, 'next', 'next.product_id', '=', 'products.id')
            ->with('category:id,name')
            ->select('products.*', 'totals.total', 'totals.low_confidence', 'next.yhat as next_yhat', 'next.yhat_lower as next_lower', 'next.yhat_upper as next_upper')
            ->search($filters['search'])
            ->when($filters['category'], fn ($query, int $category) => $query->where('products.category_id', $category))
            ->when($filters['confidence'] === 'low', fn ($query) => $query->where('totals.low_confidence', true))
            ->when(
                $filters['sort'] === 'forecast',
                fn ($query) => $query->orderByDesc('totals.total')->orderBy('products.name'),
                fn ($query) => $query->orderBy('products.name'),
            )
            ->paginate(15)
            ->withQueryString();

        $recent = $this->recentUnits($run, array_values($page->getCollection()->map(fn (Product $product) => $product->id)->all()));

        return $page->through(function (Product $product) use ($recent) {
            $total = (float) $product->getAttribute('total');
            $before = $recent[$product->id] ?? 0;

            return [
                'id' => $product->id,
                'sku' => $product->sku,
                'name' => $product->name,
                'category' => $product->category->name,
                'unit' => $product->unit,
                'next' => (float) $product->getAttribute('next_yhat'),
                'next_lower' => (float) $product->getAttribute('next_lower'),
                'next_upper' => (float) $product->getAttribute('next_upper'),
                'total' => $total,
                'recent' => $before,
                // How the forecast compares with the same number of periods just gone.
                'change' => $before > 0 ? round(($total - $before) / $before * 100, 1) : null,
                'low_confidence' => (bool) $product->getAttribute('low_confidence'),
            ];
        });
    }

    /**
     * Units sold in the last `horizon` periods of history, per product, for comparison with the forecast.
     *
     * @param  list<int>  $productIds
     * @return array<int, int>
     */
    private function recentUnits(ForecastRun $run, array $productIds): array
    {
        if ($run->as_of === null || $productIds === []) {
            return [];
        }

        $last = CarbonImmutable::instance($run->as_of);
        $from = $this->addPeriods($run->granularity, $last, -($run->horizon - 1));

        return Sale::query()
            ->where('location_id', $this->locations->id())
            ->whereIn('product_id', $productIds)
            ->whereBetween('sold_on', [$from->toDateString(), $run->granularity->endOf($last)->toDateString()])
            ->groupBy('product_id')
            ->selectRaw('product_id, sum(quantity) as units')
            ->toBase()
            ->pluck('units', 'product_id')
            ->map(fn ($units) => (int) $units)
            ->all();
    }

    /**
     * The first day of each period the run forecast.
     *
     * @return list<string>
     */
    private function forecastPeriods(ForecastRun $run): array
    {
        $periods = [];
        $start = $this->addPeriods($run->granularity, CarbonImmutable::instance($run->as_of ?? now()), 1);

        for ($step = 0; $step < $run->horizon; $step++) {
            $periods[] = $start->toDateString();
            $start = $run->granularity->next($start);
        }

        return $periods;
    }

    /**
     * @return list<array{name: string, model: array<string, mixed>, seasonal_naive: array<string, mixed>, moving_average: array<string, mixed>}>
     */
    private function perCategory(ForecastRun $run): array
    {
        $rows = [];

        foreach ($run->per_category_metrics ?? [] as $name => $scores) {
            $rows[] = ['name' => $name, ...$scores];
        }

        return $rows;
    }

    /**
     * How many periods of sales history a product has as of the run.
     */
    private function historyLength(Product $product, ForecastGranularity $granularity, ?ForecastRun $run): ?int
    {
        if ($run === null || $run->as_of === null) {
            return null;
        }

        $firstSale = Sale::query()
            ->where('location_id', $this->locations->id())
            ->where('product_id', $product->id)
            ->min('sold_on');

        if ($firstSale === null) {
            return 0;
        }

        $start = $granularity->startOf(CarbonImmutable::parse((string) $firstSale));
        $count = 0;

        for ($period = $start; $period <= $run->as_of; $period = $granularity->next($period)) {
            $count++;
        }

        return $count;
    }

    private function addPeriods(ForecastGranularity $granularity, CarbonImmutable $start, int $count): CarbonImmutable
    {
        return $granularity === ForecastGranularity::Week
            ? $start->addWeeks($count)
            : $start->addMonthsNoOverflow($count);
    }
}
