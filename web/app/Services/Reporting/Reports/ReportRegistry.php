<?php

namespace App\Services\Reporting\Reports;

use App\Models\User;
use Illuminate\Support\Collection;

/**
 * Every report there is, and which of them a person may open. To add a report,
 * write it and list it here.
 */
class ReportRegistry
{
    /**
     * @return list<class-string<Report>>
     */
    private const REPORTS = [
        SalesReport::class,
        InventoryReport::class,
        ForecastAccuracyReport::class,
        ReplenishmentHistoryReport::class,
    ];

    /**
     * @return Collection<string, Report> Keyed by report key
     */
    public function all(): Collection
    {
        return collect(self::REPORTS)
            ->map(fn (string $class) => app($class))
            ->keyBy(fn (Report $report) => $report->key());
    }

    public function find(string $key): ?Report
    {
        return $this->all()->get($key);
    }

    /**
     * The reports this person is allowed to open.
     *
     * @return Collection<string, Report>
     */
    public function availableTo(User $user): Collection
    {
        return $this->all()->filter(fn (Report $report) => $user->can($report->permission()->value));
    }
}
