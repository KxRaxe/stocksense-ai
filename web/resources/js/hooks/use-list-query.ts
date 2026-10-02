import { router } from '@inertiajs/react';
import { useRef } from 'react';

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
