import { Badge } from '@/components/ui/badge';
import type { StockStatus } from '@/types';

const statuses: Record<StockStatus, { label: string; className: string }> = {
    out_of_stock: { label: 'Out of stock', className: 'bg-critical text-ink' },
    low: { label: 'Low stock', className: 'bg-low text-ink' },
    ok: { label: 'In stock', className: 'bg-ok text-ink' },
};

export default function StockStatusBadge({ status }: { status: StockStatus }) {
    const { label, className } = statuses[status];

    return (
        <Badge variant="outline" className={className}>
            {label}
        </Badge>
    );
}
