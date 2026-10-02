<?php

namespace App\Http\Controllers;

use App\Models\Recommendation;
use App\Models\User;
use App\Services\Reporting\AuditPresenter;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Relations\MorphTo;
use Illuminate\Database\Eloquent\Relations\Relation;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;
use Inertia\Inertia;
use Inertia\Response;
use Spatie\Activitylog\Models\Activity;

/**
 * The audit log: who did what, and when, newest first. Owner only
 * (`audit.view`, see routes/web.php). Read-only: nothing here can change or
 * delete an entry.
 */
class AuditLogController extends Controller
{
    public const PER_PAGE = 25;

    public function __construct(private readonly AuditPresenter $presenter) {}

    public function index(Request $request): Response
    {
        $filters = $this->filters($request);

        $entries = Activity::query()
            ->with(['causer', 'subject' => function (Relation $subject) {
                if ($subject instanceof MorphTo) {
                    $subject->morphWith([Recommendation::class => ['product']]);
                }
            }])
            ->when($filters['area'] !== '', fn ($query) => $query->where('log_name', $filters['area']))
            ->when($filters['user'] !== null, fn ($query) => $query->where('causer_type', (new User)->getMorphClass())->where('causer_id', $filters['user']))
            ->when($filters['from'] !== '', fn ($query) => $query->where('created_at', '>=', CarbonImmutable::parse($filters['from'])->startOfDay()))
            ->when($filters['to'] !== '', fn ($query) => $query->where('created_at', '<=', CarbonImmutable::parse($filters['to'])->endOfDay()))
            ->when($filters['search'] !== '', fn ($query) => $query->where('description', 'ilike', '%'.addcslashes($filters['search'], '\\%_').'%'))
            ->orderByDesc('created_at')
            ->orderByDesc('id')
            ->paginate(self::PER_PAGE)
            ->withQueryString()
            ->through(fn (Activity $activity) => $this->presenter->present($activity));

        return Inertia::render('audit-log/index', [
            'entries' => $entries,
            'filters' => $filters,
            'areas' => Activity::query()->whereNotNull('log_name')->distinct()->orderBy('log_name')->pluck('log_name')
                ->map(fn (string $area) => ['value' => $area, 'label' => AuditPresenter::areaLabel($area)])
                ->values()
                ->all(),
            'users' => User::query()
                ->whereIn('id', Activity::query()->where('causer_type', (new User)->getMorphClass())->select('causer_id'))
                ->orderBy('name')
                ->get(['id', 'name']),
        ]);
    }

    /**
     * What the log is narrowed to. Anything invalid is ignored rather than refused: it is only a filter.
     *
     * @return array{area: string, user: int|null, from: string, to: string, search: string}
     */
    private function filters(Request $request): array
    {
        $input = Validator::make($request->query(), [
            'area' => ['nullable', 'string', 'max:100'],
            'user' => ['nullable', 'integer'],
            'from' => ['nullable', 'date_format:Y-m-d'],
            'to' => ['nullable', 'date_format:Y-m-d'],
            'search' => ['nullable', 'string', 'max:100'],
        ]);

        $bad = $input->errors()->keys();
        $value = fn (string $key) => in_array($key, $bad, true) ? null : $request->query($key);

        return [
            'area' => (string) ($value('area') ?? ''),
            'user' => $value('user') !== null && $value('user') !== '' ? (int) $value('user') : null,
            'from' => (string) ($value('from') ?? ''),
            'to' => (string) ($value('to') ?? ''),
            'search' => trim((string) ($value('search') ?? '')),
        ];
    }
}
