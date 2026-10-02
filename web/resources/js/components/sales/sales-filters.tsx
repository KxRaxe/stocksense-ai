import { X } from 'lucide-react';
import { useEffect, useState } from 'react';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { NativeSelect } from '@/components/ui/native-select';
import { useListQuery } from '@/hooks/use-list-query';
import type { SalesFilters as Filters } from '@/types';

type Props = {
    /** The sales list page, e.g. `index().url`. */
    url: string;
    filters: Filters;
};

/**
 * Search, date range and source filters for the sales list. The search waits
 * for a pause in typing; everything else applies straight away.
 */
export default function SalesFilters({ url, filters }: Props) {
    const [search, setSearch] = useState(filters.search);
    const apply = useListQuery(url, filters);

    useEffect(() => {
        if (search === filters.search) {
            return;
        }

        const timer = setTimeout(() => apply({ search }), 300);

        return () => clearTimeout(timer);
        // `apply` always reads the latest filters, so it is not a dependency.
        // eslint-disable-next-line react-hooks/exhaustive-deps
    }, [search]);

    return (
        <div className="space-y-3">
            <div className="flex flex-wrap items-end gap-3">
                <Input
                    type="search"
                    value={search}
                    onChange={(event) => setSearch(event.target.value)}
                    placeholder="Search name or SKU"
                    aria-label="Search"
                    className="w-full sm:w-64"
                    data-test="sales-search"
                />

                <label className="grid gap-1 text-xs text-muted-foreground">
                    From
                    <Input
                        type="date"
                        value={filters.from ?? ''}
                        onChange={(event) =>
                            apply({ from: event.target.value || null })
                        }
                        aria-label="From date"
                        className="w-40"
                    />
                </label>

                <label className="grid gap-1 text-xs text-muted-foreground">
                    To
                    <Input
                        type="date"
                        value={filters.to ?? ''}
                        onChange={(event) =>
                            apply({ to: event.target.value || null })
                        }
                        aria-label="To date"
                        className="w-40"
                    />
                </label>

                <NativeSelect
                    value={filters.source}
                    onChange={(event) => apply({ source: event.target.value })}
                    aria-label="Source"
                    className="w-full sm:w-48"
                >
                    <option value="">All sources</option>
                    <option value="manual">Entered by hand</option>
                    <option value="import">Imported from a file</option>
                </NativeSelect>
            </div>

            {filters.import !== null && (
                <div className="flex items-center gap-2 text-sm">
                    <span className="rounded-md bg-muted px-2 py-1">
                        Showing sales from import #{filters.import}
                    </span>
                    <Button
                        variant="ghost"
                        size="sm"
                        onClick={() => apply({ import: null })}
                    >
                        <X />
                        Show all
                    </Button>
                </div>
            )}
        </div>
    );
}
