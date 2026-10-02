import { Badge } from '@/components/ui/badge';
import type { StockStatus } from '@/types';

const statuses: Record<
    StockStatus,
    { label: string; variant: 'destructive' | 'secondary' | 'outline' }
> = {
    out_of_stock: { label: 'Out of stock', variant: 'destructive' },
    low: { label: 'Low stock', variant: 'secondary' },
    ok: { label: 'In stock', variant: 'outline' },
};

export default function StockStatusBadge({ status }: { status: StockStatus }) {
    const { label, variant } = statuses[status];

    return (
        <Badge
            variant={variant}
            className={
                status === 'low'
                    ? 'bg-amber-500/15 text-amber-700 dark:text-amber-400'
                    : undefined
            }
        >
            {label}
        </Badge>
    );
}
