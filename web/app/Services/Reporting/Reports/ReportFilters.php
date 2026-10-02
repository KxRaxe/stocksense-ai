<?php

namespace App\Services\Reporting\Reports;

use App\Enums\ForecastGranularity;
use App\Models\Category;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Validator;

/**
 * What a report is narrowed to. Built from the address's query string: anything
 * missing or invalid falls back to the report's default, and each problem is
 * reported, so a mistyped link still shows something sensible.
 */
final class ReportFilters
{
    /** The longest period a report may cover, so one request cannot ask for years of rows. */
    public const MAX_DAYS = 1096;

    public function __construct(
        public readonly CarbonImmutable $from,
        public readonly CarbonImmutable $to,
        public readonly ?int $categoryId,
        public readonly ForecastGranularity $granularity,
    ) {}

    /**
     * @param  array<string, mixed>  $input  The query string
     * @return array{0: self, 1: array<string, string>} The filters, and what was wrong with the input
     */
    public static function fromInput(array $input, Report $report, ?CarbonImmutable $today = null): array
    {
        $today ??= CarbonImmutable::today();
        $supports = $report->filters();
        [$defaultFrom, $defaultTo] = $report->defaultRange($today);
        $errors = [];

        $validator = Validator::make($input, [
            'from' => ['nullable', 'date_format:Y-m-d'],
            'to' => ['nullable', 'date_format:Y-m-d'],
            'category' => ['nullable', 'integer', 'exists:categories,id'],
            'granularity' => ['nullable', 'in:week,month'],
        ], [
            'from.date_format' => 'The start date must be a date such as 2026-09-01.',
            'to.date_format' => 'The end date must be a date such as 2026-09-30.',
            'category.exists' => 'That category does not exist.',
            'granularity.in' => 'Choose weekly or monthly.',
        ]);

        $errors = $validator->errors()->all() === [] ? [] : array_map(fn (array $messages) => $messages[0], $validator->errors()->toArray());
        $valid = fn (string $key) => ! isset($errors[$key]) && isset($input[$key]) && $input[$key] !== '';

        $from = in_array('date', $supports, true) && $valid('from') ? CarbonImmutable::parse((string) $input['from'])->startOfDay() : $defaultFrom;
        $to = in_array('date', $supports, true) && $valid('to') ? CarbonImmutable::parse((string) $input['to'])->startOfDay() : $defaultTo;

        if ($from > $to) {
            $errors['to'] = 'The end date cannot be before the start date.';
            [$from, $to] = [$defaultFrom, $defaultTo];
        } elseif ($from->diffInDays($to) >= self::MAX_DAYS) {
            $errors['to'] = 'A report can cover at most three years.';
            [$from, $to] = [$defaultFrom, $defaultTo];
        }

        $category = in_array('category', $supports, true) && $valid('category') ? (int) $input['category'] : null;
        $granularity = in_array('granularity', $supports, true) && $valid('granularity')
            ? ForecastGranularity::from((string) $input['granularity'])
            : ForecastGranularity::Week;

        return [new self($from, $to, $category, $granularity), $errors];
    }

    /**
     * The filters as a short sentence for the top of an export: "6 Sep 2026 to 5 Oct 2026, Hardware".
     */
    public function describe(Report $report): string
    {
        $parts = [];

        if (in_array('date', $report->filters(), true)) {
            $parts[] = $this->from->format('j M Y').' to '.$this->to->format('j M Y');
        }

        if (in_array('granularity', $report->filters(), true)) {
            $parts[] = $this->granularity->label().' forecasts';
        }

        if ($this->categoryId !== null) {
            $parts[] = 'Category: '.(Category::query()->whereKey($this->categoryId)->value('name') ?? 'unknown');
        } elseif (in_array('category', $report->filters(), true)) {
            $parts[] = 'All categories';
        }

        return implode(' · ', $parts);
    }

    /**
     * The same filters as a query string, for the export links.
     *
     * @return array<string, string|int>
     */
    public function toQuery(Report $report): array
    {
        $query = [];

        if (in_array('date', $report->filters(), true)) {
            $query['from'] = $this->from->toDateString();
            $query['to'] = $this->to->toDateString();
        }

        if (in_array('granularity', $report->filters(), true)) {
            $query['granularity'] = $this->granularity->value;
        }

        if ($this->categoryId !== null) {
            $query['category'] = $this->categoryId;
        }

        return $query;
    }
}
