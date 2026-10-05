<?php

namespace App\Services\Notifications;

use App\Enums\ForecastGranularity;
use App\Enums\ForecastStatus;
use App\Enums\ImportStatus;
use App\Enums\Permission;
use App\Enums\RecommendationStatus;
use App\Enums\Role;
use App\Models\ForecastRun;
use App\Models\ImportBatch;
use App\Models\Recommendation;
use App\Models\User;
use App\Notifications\CriticalStockNotification;
use App\Notifications\ForecastRunNotification;
use App\Notifications\ImportErrorsNotification;
use App\Notifications\ReplenishmentDigestNotification;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Notification;

/**
 * Decides who is told what, and when. The notifications themselves decide
 * how, from each person's settings (see StockSenseNotification::via()).
 *
 * - Critical stock: Owner and Manager, once a day for each product.
 * - Digest: Owner and Manager, as often as each of them chose.
 * - Forecast run: the person who started it, and the Owner.
 * - Import with problems: the person who uploaded the file.
 *
 * Only people whose accounts are active are ever notified.
 */
class NotificationDispatcher
{
    /**
     * Tells the people who decide about reordering which products are critical,
     * leaving out any they were already told about today.
     *
     * @param  Collection<int, Recommendation>  $recommendations  Critical, open recommendations
     * @return int How many products were reported
     */
    public function criticalStock(Collection $recommendations): int
    {
        $today = CarbonImmutable::today();
        $items = [];

        foreach ($recommendations as $recommendation) {
            // Cache::add only succeeds for the first caller, so a product is reported once a day.
            $first = Cache::add("critical-alert:{$recommendation->product_id}:{$recommendation->location_id}:{$today->toDateString()}", true, $today->endOfDay());

            if ($first) {
                $items[] = [
                    'name' => $recommendation->product->name,
                    'sku' => $recommendation->product->sku,
                    'available' => $recommendation->on_hand + $recommendation->on_order,
                    'expected' => (float) $recommendation->lead_time_demand,
                ];
            }
        }

        $recipients = $this->deciders();

        if ($items === [] || $recipients->isEmpty()) {
            return 0;
        }

        Notification::send($recipients, new CriticalStockNotification($items));

        return count($items);
    }

    /**
     * Sends the digest to everyone whose chosen frequency is among `$frequencies`.
     * Says nothing when nothing needs ordering.
     *
     * @param  list<string>  $frequencies  'daily', 'weekly'
     * @return int How many people it went to
     */
    public function digest(array $frequencies, ?CarbonImmutable $today = null): int
    {
        $today ??= CarbonImmutable::today();

        $counts = ['critical' => 0, 'low' => 0, 'watch' => 0, 'overstock' => 0];

        foreach (Recommendation::query()->open()->selectRaw('risk_level, count(*) as total')->groupBy('risk_level')->toBase()->get() as $row) {
            if (isset($counts[$row->risk_level])) {
                $counts[$row->risk_level] = (int) $row->total;
            }
        }

        if ($counts['critical'] + $counts['low'] + $counts['watch'] === 0) {
            return 0;
        }

        $top = Recommendation::query()
            ->with('product:id,name,sku')
            ->where('status', RecommendationStatus::Pending->value)
            ->mostUrgentFirst()
            ->limit(5)
            ->get()
            ->map(fn (Recommendation $recommendation) => [
                'name' => $recommendation->product->name,
                'sku' => $recommendation->product->sku,
                'risk' => $recommendation->risk_level->label(),
                'quantity' => $recommendation->recommended_qty,
                'when' => $recommendation->order_by_date === null || $recommendation->order_by_date->lte($today)
                    ? 'now'
                    : 'by '.$recommendation->order_by_date->format('M j'),
            ])
            ->all();

        $top = array_values($top);

        $run = ForecastRun::latestCompleted(ForecastGranularity::Week) ?? ForecastRun::latestCompleted(ForecastGranularity::Month);
        $age = $run?->finished_at === null ? null : (int) round($run->finished_at->startOfDay()->diffInDays($today));

        $preferences = app(NotificationPreferences::class);
        $recipients = $this->deciders()->filter(fn (User $user) => in_array($preferences->digestFrequency($user), $frequencies, true));

        if ($recipients->isEmpty()) {
            return 0;
        }

        Notification::send($recipients, new ReplenishmentDigestNotification($counts, $top, $age));

        return $recipients->count();
    }

    /**
     * Tells whoever started a forecast run, and the Owner, how it went.
     */
    public function forecastRun(ForecastRun $run): void
    {
        $recipients = User::query()->where('is_active', true)->role(Role::Owner->value)->get();

        if ($run->triggered_by !== null) {
            $starter = User::query()->where('is_active', true)->find($run->triggered_by);

            if ($starter !== null) {
                $recipients->push($starter);
            }
        }

        $recipients = $recipients->unique('id')->values();

        if ($recipients->isEmpty()) {
            return;
        }

        $failed = $run->status === ForecastStatus::Failed;

        Notification::send($recipients, new ForecastRunNotification(
            $run->granularity,
            $run->horizon,
            $failed ? ($run->error_message ?? 'It stopped unexpectedly.') : null,
            $failed ? null : [
                'model' => $run->metrics['wape'] ?? null,
                'seasonal_naive' => $run->baseline_metrics['seasonal_naive']['wape'] ?? null,
            ],
        ));
    }

    /**
     * Tells the person who uploaded a file when it had rows that could not be
     * imported, or stopped partway. Nothing is sent for a clean import.
     */
    public function importFinished(ImportBatch $batch): void
    {
        $stopped = $batch->status === ImportStatus::Failed;

        if ($batch->user_id === null || ($batch->rows_failed === 0 && ! $stopped)) {
            return;
        }

        $uploader = User::query()->where('is_active', true)->find($batch->user_id);

        $uploader?->notify(new ImportErrorsNotification(
            $batch->id,
            $batch->type,
            $batch->rows_ok,
            $batch->rows_failed,
            $stopped,
            route("{$batch->type->value}.imports.show", $batch, absolute: false),
        ));
    }

    /**
     * The people who decide about reordering: Owner and Manager.
     *
     * @return Collection<int, User>
     */
    private function deciders(): Collection
    {
        return User::query()
            ->where('is_active', true)
            ->permission(Permission::DecideRecommendations->value)
            ->get();
    }
}
