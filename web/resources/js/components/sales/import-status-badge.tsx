import { Badge } from '@/components/ui/badge';
import type { ImportStatus } from '@/types';

const variants: Record<
    ImportStatus,
    'default' | 'secondary' | 'destructive' | 'outline'
> = {
    preview: 'outline',
    queued: 'secondary',
    processing: 'secondary',
    completed: 'default',
    failed: 'destructive',
    undone: 'outline',
};

export default function ImportStatusBadge({
    status,
    label,
}: {
    status: ImportStatus;
    label: string;
}) {
    return <Badge variant={variants[status]}>{label}</Badge>;
}
