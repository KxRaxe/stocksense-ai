import { useEffect, useState } from 'react';
import { Input } from '@/components/ui/input';
import { NativeSelect } from '@/components/ui/native-select';
import { useListQuery } from '@/hooks/use-list-query';
import type { Option, RecommendationFilters as Filters } from '@/types';

type Props = {
    url: string;
    filters: Filters;
    categories: Option[];
};

/**
 * Search, category and risk for the recommendations. They reload the list as
 * they change and live in the URL; the group being shown (to do, overstock,
 * decided) is kept.
 */
export default function RecommendationFilters({
    url,
    filters,
    categories,
}: Props) {
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
                value={filters.risk}
                onChange={(event) =>
                    apply({ risk: event.target.value || null })
                }
                aria-label="Risk"
                className="w-full sm:w-44"
                data-test="risk-filter"
            >
                <option value="">Any risk</option>
                <option value="critical">Critical</option>
                <option value="low">Low</option>
                <option value="watch">Watch</option>
            </NativeSelect>
        </div>
    );
}
