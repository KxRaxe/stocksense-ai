<?php

namespace App\Http\Controllers;

use App\Enums\ForecastGranularity;
use App\Enums\RecommendationStatus;
use App\Enums\RiskLevel;
use App\Jobs\GenerateRecommendationsJob;
use App\Models\Category;
use App\Models\ForecastRun;
use App\Models\Recommendation;
use App\Services\Replenishment\RecommendationDecisions;
use Carbon\CarbonImmutable;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/**
 * What to reorder, with the reasoning, and the decisions people make about it.
 * Looking needs `recommendations.view`; deciding needs `recommendations.decide`
 * (see routes/web.php). The system never orders anything: accepting records
 * that a person will, and counts the quantity as on order.
 *
 * @phpstan-type Row array<string, mixed>
 */
class RecommendationController extends Controller
{
    public function __construct(private readonly RecommendationDecisions $decisions) {}

    public function index(Request $request): Response
    {
        $filters = [
            'view' => in_array($request->query('view'), ['todo', 'overstock', 'decided'], true) ? $request->query('view') : 'todo',
            'risk' => RiskLevel::tryFrom((string) $request->query('risk'))->value ?? '',
            'category' => $request->query('category') !== null ? (int) $request->query('category') : null,
            'search' => (string) $request->query('search', ''),
        ];

        $query = Recommendation::query()
            ->with(['product:id,sku,name,unit,category_id,moq,pack_size', 'product.category:id,name', 'decider:id,name'])
            ->join('products', 'products.id', '=', 'replenishment_recommendations.product_id')
            ->select('replenishment_recommendations.*')
            ->when($filters['view'] === 'todo', fn ($q) => $q->where('replenishment_recommendations.status', RecommendationStatus::Pending->value))
            ->when($filters['view'] === 'overstock', fn ($q) => $q->where('replenishment_recommendations.status', RecommendationStatus::Info->value))
            ->when($filters['view'] === 'decided', fn ($q) => $q->whereIn('replenishment_recommendations.status', [
                RecommendationStatus::Accepted->value, RecommendationStatus::Adjusted->value,
                RecommendationStatus::Dismissed->value, RecommendationStatus::Cancelled->value,
            ]))
            ->when($filters['risk'] !== '', fn ($q) => $q->where('replenishment_recommendations.risk_level', $filters['risk']))
            ->when($filters['category'], fn ($q, int $category) => $q->where('products.category_id', $category))
            ->when($filters['search'] !== '', fn ($q) => $q->whereHas('product', fn ($product) => $product->search($filters['search'])));

        $ordered = $filters['view'] === 'decided'
            ? $query->orderByDesc('replenishment_recommendations.decided_at')->orderByDesc('replenishment_recommendations.id')
            : $query->mostUrgentFirst();

        $rows = $ordered->paginate(15)->withQueryString()->through(fn (Recommendation $recommendation) => $this->present($recommendation));

        return Inertia::render('recommendations/index', [
            'recommendations' => $rows,
            'counts' => $this->counts(),
            'filters' => $filters,
            'categories' => Category::query()->orderBy('name')->get(['id', 'name']),
            'forecast' => $this->forecast(),
            'can' => ['decide' => (bool) $request->user()?->can('recommendations.decide')],
        ]);
    }

    public function accept(Request $request, Recommendation $recommendation): RedirectResponse
    {
        $data = $request->validate(['note' => ['nullable', 'string', 'max:500']]);

        $this->decisions->accept($recommendation, $request->user(), $data['note'] ?? null);

        Inertia::flash('toast', ['type' => 'success', 'message' => 'Accepted. The quantity now counts as on order.']);

        return back();
    }

    public function adjust(Request $request, Recommendation $recommendation): RedirectResponse
    {
        $data = $request->validate([
            'quantity' => ['required', 'integer', 'min:1', 'max:'.RecommendationDecisions::MAX_QUANTITY],
            'note' => ['nullable', 'string', 'max:500'],
        ]);

        $this->decisions->adjust($recommendation, $request->user(), (int) $data['quantity'], $data['note'] ?? null);

        Inertia::flash('toast', ['type' => 'success', 'message' => 'Accepted with your quantity. It now counts as on order.']);

        return back();
    }

    public function dismiss(Request $request, Recommendation $recommendation): RedirectResponse
    {
        $data = $request->validate(['note' => ['nullable', 'string', 'max:500']]);

        $this->decisions->dismiss($recommendation, $request->user(), $data['note'] ?? null);

        Inertia::flash('toast', ['type' => 'success', 'message' => 'Dismissed. This product will not be recommended again for '.(int) config('replenishment.snooze_days').' days.']);

        return back();
    }

    public function cancel(Request $request, Recommendation $recommendation): RedirectResponse
    {
        $data = $request->validate(['note' => ['nullable', 'string', 'max:500']]);

        $this->decisions->cancel($recommendation, $request->user(), $data['note'] ?? null);

        Inertia::flash('toast', ['type' => 'success', 'message' => 'Order cancelled. It no longer counts as on order.']);

        return back();
    }

    /**
     * Recalculates now, for after stock has changed: the daily refresh is not far off, but this saves waiting.
     */
    public function refresh(): RedirectResponse
    {
        GenerateRecommendationsJob::dispatch();

        Inertia::flash('toast', ['type' => 'success', 'message' => 'Refreshing the recommendations. This takes a moment.']);

        return back();
    }

    /**
     * How many products are at each level, for the cards at the top of the page.
     *
     * @return array{critical: int, low: int, watch: int, overstock: int, ordered: int}
     */
    private function counts(): array
    {
        $open = Recommendation::query()->open()->selectRaw('risk_level, count(*) as total')->groupBy('risk_level')->toBase()->pluck('total', 'risk_level');

        return [
            'critical' => (int) ($open[RiskLevel::Critical->value] ?? 0),
            'low' => (int) ($open[RiskLevel::Low->value] ?? 0),
            'watch' => (int) ($open[RiskLevel::Watch->value] ?? 0),
            'overstock' => (int) ($open[RiskLevel::Overstock->value] ?? 0),
            'ordered' => Recommendation::query()->whereIn('status', [RecommendationStatus::Accepted->value, RecommendationStatus::Adjusted->value])->count(),
        ];
    }

    /**
     * The forecast the advice is built on, and whether it is getting old.
     *
     * @return array{id: int, granularity: string, finished_at: string|null, age_days: int|null, stale: bool}|null
     */
    private function forecast(): ?array
    {
        $run = ForecastRun::latestCompleted(ForecastGranularity::Week) ?? ForecastRun::latestCompleted(ForecastGranularity::Month);

        if ($run === null) {
            return null;
        }

        $age = $run->finished_at === null ? null : (int) round($run->finished_at->startOfDay()->diffInDays(CarbonImmutable::today()));

        return [
            'id' => $run->id,
            'granularity' => $run->granularity->value,
            'finished_at' => $run->finished_at?->toIso8601String(),
            'age_days' => $age,
            'stale' => $age !== null && $age > (int) config('replenishment.stale_forecast_days'),
        ];
    }

    /**
     * @return Row
     */
    private function present(Recommendation $recommendation): array
    {
        $product = $recommendation->product;

        return [
            'id' => $recommendation->id,
            'product' => [
                'id' => $product->id,
                'sku' => $product->sku,
                'name' => $product->name,
                'category' => $product->category->name,
                'unit' => $product->unit,
                'moq' => $product->moq,
                'pack_size' => $product->pack_size,
            ],
            'risk' => $recommendation->risk_level->value,
            'risk_label' => $recommendation->risk_level->label(),
            'status' => $recommendation->status->value,
            'status_label' => $recommendation->status->label(),
            'on_hand' => $recommendation->on_hand,
            'on_order' => $recommendation->on_order,
            'lead_time_days' => $recommendation->lead_time_days,
            'lead_time_demand' => (float) $recommendation->lead_time_demand,
            'safety_stock' => $recommendation->safety_stock,
            'reorder_point' => $recommendation->reorder_point,
            'order_up_to' => $recommendation->order_up_to,
            'days_of_cover' => $recommendation->days_of_cover === null ? null : (float) $recommendation->days_of_cover,
            'recommended_qty' => $recommendation->recommended_qty,
            'order_by_date' => $recommendation->order_by_date?->toDateString(),
            'explanation' => $recommendation->explanation,
            'final_qty' => $recommendation->final_qty,
            'decided_by' => $recommendation->decider?->name,
            'decided_at' => $recommendation->decided_at?->toIso8601String(),
            'note' => $recommendation->note,
            'snoozed_until' => $recommendation->snoozed_until?->toDateString(),
            'cancelled_at' => $recommendation->cancelled_at?->toIso8601String(),
            'is_pending' => $recommendation->status === RecommendationStatus::Pending,
            'is_ordered' => $recommendation->status->isOrdered(),
        ];
    }
}
