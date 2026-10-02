import { router } from '@inertiajs/react';
import { useState } from 'react';
import type { FormEvent } from 'react';
import InputError from '@/components/input-error';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { NativeSelect } from '@/components/ui/native-select';
import { show } from '@/routes/reports';
import type {
    Option,
    ReportFilterName,
    ReportFiltersState,
    ForecastGranularity,
} from '@/types';

type Props = {
    /** The report's key, for the address. */
    reportKey: string;
    /** Which filters this report understands. */
    supports: ReportFilterName[];
    filters: ReportFiltersState;
    categories: Option[];
    /** What was wrong with the filters in the address, by filter. */
    errors: Record<string, string>;
};

/**
 * The filters a report understands: a period, a category, weekly or monthly.
 * Applied together with a button, since changing a date is usually two edits.
 */
export default function ReportFilters({
    reportKey,
    supports,
    filters,
    categories,
    errors,
}: Props) {
    const [from, setFrom] = useState(filters.from);
    const [to, setTo] = useState(filters.to);
    const [category, setCategory] = useState(
        filters.category === null ? '' : String(filters.category),
    );
    const [granularity, setGranularity] = useState<ForecastGranularity>(
        filters.granularity,
    );

    const submit = (event: FormEvent) => {
        event.preventDefault();

        const query: Record<string, string> = {};

        if (supports.includes('date')) {
            query.from = from;
            query.to = to;
        }

        if (supports.includes('granularity')) {
            query.granularity = granularity;
        }

        if (category !== '') {
            query.category = category;
        }

        router.get(show.url(reportKey), query, { preserveScroll: true });
    };

    return (
        <form
            onSubmit={submit}
            className="flex flex-wrap items-end gap-3"
            data-test="report-filters"
        >
            {supports.includes('date') && (
                <>
                    <div className="grid gap-1.5">
                        <Label htmlFor="report-from">From</Label>
                        <Input
                            id="report-from"
                            type="date"
                            value={from}
                            max={to}
                            onChange={(event) => setFrom(event.target.value)}
                            className="w-40"
                            data-test="filter-from"
                        />
                        <InputError message={errors.from} />
                    </div>
                    <div className="grid gap-1.5">
                        <Label htmlFor="report-to">To</Label>
                        <Input
                            id="report-to"
                            type="date"
                            value={to}
                            min={from}
                            onChange={(event) => setTo(event.target.value)}
                            className="w-40"
                            data-test="filter-to"
                        />
                        <InputError message={errors.to} />
                    </div>
                </>
            )}

            {supports.includes('granularity') && (
                <div className="grid gap-1.5">
                    <Label htmlFor="report-granularity">Forecasts</Label>
                    <NativeSelect
                        id="report-granularity"
                        value={granularity}
                        onChange={(event) =>
                            setGranularity(
                                event.target.value as ForecastGranularity,
                            )
                        }
                        className="w-36"
                        data-test="filter-granularity"
                    >
                        <option value="week">Weekly</option>
                        <option value="month">Monthly</option>
                    </NativeSelect>
                    <InputError message={errors.granularity} />
                </div>
            )}

            {supports.includes('category') && (
                <div className="grid gap-1.5">
                    <Label htmlFor="report-category">Category</Label>
                    <NativeSelect
                        id="report-category"
                        value={category}
                        onChange={(event) => setCategory(event.target.value)}
                        className="w-52"
                        data-test="filter-category"
                    >
                        <option value="">All categories</option>
                        {categories.map((option) => (
                            <option key={option.id} value={option.id}>
                                {option.name}
                            </option>
                        ))}
                    </NativeSelect>
                    <InputError message={errors.category} />
                </div>
            )}

            <Button type="submit" data-test="apply-filters">
                Show report
            </Button>
        </form>
    );
}
