import { router } from '@inertiajs/react';
import { useEffect, useRef, useState } from 'react';

type Query = Record<string, string | number | null>;

/**
 * Returns a function that reloads a list page with some filters changed.
 * Empty values are left out of the URL so it stays short, and filters live in
 * the URL so a filtered list can be bookmarked or shared.
 */
export function useListQuery(url: string, current: Query) {
    // Always reads the latest filters, even from a timer that was set earlier.
    const latest = useRef(current);
    latest.current = current;

    return (changes: Query) => {
        const next = { ...latest.current, ...changes };

        const query = Object.fromEntries(
            Object.entries(next).filter(
                ([, value]) => value !== null && value !== '',
            ),
        );

        router.get(url, query, {
            preserveState: true,
            preserveScroll: true,
            replace: true,
        });
    };
}

/**
 * The text in a search box, applied with `apply` once typing pauses for 300 ms.
 * `current` is the search the list is showing now.
 */
export function useDebouncedSearch(
    current: string,
    apply: (changes: Query) => void,
) {
    const [search, setSearch] = useState(current);

    useEffect(() => {
        if (search === current) {
            return;
        }

        const timer = setTimeout(() => apply({ search }), 300);

        return () => clearTimeout(timer);
        // `apply` always reads the latest filters, so it is not a dependency.
        // eslint-disable-next-line react-hooks/exhaustive-deps
    }, [search]);

    return [search, setSearch] as const;
}
