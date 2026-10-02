import { Link } from '@inertiajs/react';
import { Button } from '@/components/ui/button';
import { formatNumber } from '@/lib/format';
import type { Paginated } from '@/types';

/** "Showing 1-15 of 42" with Previous and Next links that keep the filters. */
export default function Pagination({
    paginator,
}: {
    paginator: Paginated<unknown>;
}) {
    if (paginator.total === 0) {
        return null;
    }

    return (
        <div className="flex items-center justify-between gap-4 text-sm text-muted-foreground">
            <p>
                Showing {formatNumber(paginator.from ?? 0)}-
                {formatNumber(paginator.to ?? 0)} of{' '}
                {formatNumber(paginator.total)}
            </p>

            {paginator.last_page > 1 && (
                <div className="flex gap-2">
                    <Button
                        variant="outline"
                        size="sm"
                        asChild={paginator.prev_page_url !== null}
                        disabled={paginator.prev_page_url === null}
                    >
                        {paginator.prev_page_url !== null ? (
                            <Link href={paginator.prev_page_url} preserveScroll>
                                Previous
                            </Link>
                        ) : (
                            'Previous'
                        )}
                    </Button>
                    <Button
                        variant="outline"
                        size="sm"
                        asChild={paginator.next_page_url !== null}
                        disabled={paginator.next_page_url === null}
                    >
                        {paginator.next_page_url !== null ? (
                            <Link href={paginator.next_page_url} preserveScroll>
                                Next
                            </Link>
                        ) : (
                            'Next'
                        )}
                    </Button>
                </div>
            )}
        </div>
    );
}
