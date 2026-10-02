<?php

namespace App\Services\Reporting\Reports;

use App\Enums\Permission;
use App\Enums\RecommendationStatus;
use App\Models\Recommendation;
use Carbon\CarbonImmutable;

/**
 * Every recommendation raised in a period and what became of it: how much was
 * advised, what was decided and by whom. A record of the advice and the
 * decisions, since the system itself never orders anything.
 */
class ReplenishmentHistoryReport implements Report
{
    public function key(): string
    {
        return 'replenishment';
    }

    public function title(): string
    {
        return 'Replenishment history';
    }

    public function description(): string
    {
        return 'The reorder advice raised over a period, and what was decided about each: accepted, changed, dismissed or cancelled.';
    }

    public function permission(): Permission
    {
        return Permission::ViewReports;
    }

    public function filters(): array
    {
        return ['date', 'category'];
    }

    public function defaultRange(CarbonImmutable $today): array
    {
        return [$today->subDays(89), $today];
    }

    public function run(ReportFilters $filters): ReportResult
    {
        $recommendations = Recommendation::query()
            ->with(['product:id,sku,name,unit,category_id', 'product.category:id,name', 'decider:id,name'])
            ->where('status', '!=', RecommendationStatus::Info->value)
            ->whereBetween('replenishment_recommendations.created_at', [$filters->from->startOfDay(), $filters->to->endOfDay()])
            ->when($filters->categoryId, fn ($query, int $category) => $query->whereHas('product', fn ($product) => $product->where('category_id', $category)))
            ->orderByDesc('replenishment_recommendations.created_at')
            ->orderByDesc('replenishment_recommendations.id')
            ->get();

        $counts = $recommendations->countBy(fn (Recommendation $recommendation) => $recommendation->status->value);

        $accepted = ($counts[RecommendationStatus::Accepted->value] ?? 0) + ($counts[RecommendationStatus::Adjusted->value] ?? 0) + ($counts[RecommendationStatus::Cancelled->value] ?? 0);
        $decided = $accepted + ($counts[RecommendationStatus::Dismissed->value] ?? 0);

        $rows = $recommendations->map(fn (Recommendation $recommendation) => [
            'raised_on' => $recommendation->created_at?->toDateString(),
            'sku' => $recommendation->product->sku,
            'name' => $recommendation->product->name,
            'category' => $recommendation->product->category->name,
            'unit' => $recommendation->product->unit,
            'risk' => $recommendation->risk_level->label(),
            'recommended_qty' => $recommendation->recommended_qty,
            'final_qty' => $recommendation->final_qty,
            'status' => $recommendation->status->label(),
            'decided_by' => $recommendation->decider?->name,
            'decided_at' => $recommendation->decided_at?->toIso8601String(),
            'note' => $recommendation->note,
        ])->values()->all();

        return new ReportResult(
            columns: [
                new ReportColumn('raised_on', 'Raised', ReportColumn::DATE),
                new ReportColumn('sku', 'SKU'),
                new ReportColumn('name', 'Product'),
                new ReportColumn('category', 'Category'),
                new ReportColumn('unit', 'Unit'),
                new ReportColumn('risk', 'Risk'),
                new ReportColumn('recommended_qty', 'Recommended', ReportColumn::INTEGER),
                new ReportColumn('final_qty', 'Ordered', ReportColumn::INTEGER),
                new ReportColumn('status', 'Outcome'),
                new ReportColumn('decided_by', 'Decided by'),
                new ReportColumn('decided_at', 'Decided', ReportColumn::DATETIME),
                new ReportColumn('note', 'Note'),
            ],
            rows: array_values($rows),
            summary: [
                ['label' => 'Recommendations', 'value' => count($rows), 'type' => ReportColumn::INTEGER],
                ['label' => 'Accepted', 'value' => $accepted, 'type' => ReportColumn::INTEGER],
                ['label' => 'Dismissed', 'value' => $counts[RecommendationStatus::Dismissed->value] ?? 0, 'type' => ReportColumn::INTEGER],
                ['label' => 'Waiting for a decision', 'value' => $counts[RecommendationStatus::Pending->value] ?? 0, 'type' => ReportColumn::INTEGER],
                ['label' => 'Accepted, of those decided', 'value' => $decided > 0 ? round($accepted / $decided * 100, 1) : null, 'type' => ReportColumn::PERCENT],
            ],
            notes: [
                'Accepted includes orders accepted with a changed quantity and orders that were accepted and later cancelled. "Ordered" is the quantity the person agreed to order. The system never orders anything itself.',
                'Overstock notices are information only and are not listed.',
            ],
        );
    }
}
