import { Input } from '@/components/ui/input';
import { NativeSelect } from '@/components/ui/native-select';
import { useDebouncedSearch, useListQuery } from '@/hooks/use-list-query';
import type { ForecastFilters as Filters, Option } from '@/types';

type Props = {
    /** The forecast list's address, with the granularity already in it. */
    url: string;
    granularity: string;
    filters: Filters;
    categories: Option[];
};

/**
 * Search, category, confidence and sort for the forecast list. They reload the
 * list as they change and live in the URL, like the other lists.
 */
export default function ForecastFilters({
    url,
    granularity,
    filters,
    categories,
}: Props) {
    // The granularity is part of the page, so every change keeps it.
    const apply = useListQuery(url, { ...filters, granularity });
    const [search, setSearch] = useDebouncedSearch(filters.search, apply);

    return (
        <div className="flex flex-wrap gap-3">
            <Input
                type="search"
                value={search}
                onChange={(event) => setSearch(event.target.value)}
                placeholder="Search name or SKU"
                aria-label="Search"
                className="w-full sm:w-64"
                data-test="list-search"
            />

            <NativeSelect
                value={filters.category ?? ''}
                onChange={(event) =>
                    apply({ category: event.target.value || null })
                }
                aria-label="Category"
                className="w-full sm:w-52"
            >
                <option value="">All categories</option>
                {categories.map((category) => (
                    <option key={category.id} value={category.id}>
                        {category.name}
                    </option>
                ))}
            </NativeSelect>

            <NativeSelect
                value={filters.confidence}
                onChange={(event) =>
                    apply({ confidence: event.target.value || null })
                }
                aria-label="Confidence"
                className="w-full sm:w-52"
            >
                <option value="">All products</option>
                <option value="low">Low confidence only</option>
            </NativeSelect>

            <NativeSelect
                value={filters.sort}
                onChange={(event) => apply({ sort: event.target.value })}
                aria-label="Sort by"
                className="w-full sm:w-52"
            >
                <option value="name">Sort by name</option>
                <option value="forecast">Sort by most expected</option>
            </NativeSelect>
        </div>
    );
}
