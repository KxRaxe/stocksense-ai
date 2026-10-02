import { useEffect, useState } from 'react';
import { Input } from '@/components/ui/input';
import { NativeSelect } from '@/components/ui/native-select';
import { useListQuery } from '@/hooks/use-list-query';
import type { Option } from '@/types';

type Choice = { value: string; label: string };

type Props = {
    /** The page the filters belong to, e.g. `products.index().url`. */
    url: string;
    filters: Record<string, string | number | null>;
    categories: Option[];
    /** An extra dropdown, such as stock status. */
    extra?: { name: string; label: string; choices: Choice[] };
};

/**
 * Search box plus dropdowns that reload the list as they change. The search
 * waits for a short pause in typing; dropdowns apply straight away. Filters
 * live in the URL, so a filtered list can be bookmarked or shared.
 */
export default function ListFilters({
    url,
    filters,
    categories,
    extra,
}: Props) {
    const [search, setSearch] = useState(String(filters.search ?? ''));
    const apply = useListQuery(url, filters);

    useEffect(() => {
        if (search === String(filters.search ?? '')) {
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

            {extra && (
                <NativeSelect
                    value={String(filters[extra.name] ?? '')}
                    onChange={(event) =>
                        apply({ [extra.name]: event.target.value })
                    }
                    aria-label={extra.label}
                    className="w-full sm:w-44"
                >
                    {extra.choices.map((choice) => (
                        <option key={choice.value} value={choice.value}>
                            {choice.label}
                        </option>
                    ))}
                </NativeSelect>
            )}
        </div>
    );
}
