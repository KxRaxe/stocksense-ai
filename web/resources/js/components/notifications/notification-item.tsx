import { Link } from '@inertiajs/react';
import {
    Bell,
    ClipboardList,
    FileWarning,
    TrendingUp,
    TriangleAlert,
} from 'lucide-react';
import type { LucideIcon } from 'lucide-react';
import { timeAgo } from '@/lib/replenishment';
import { cn } from '@/lib/utils';
import { open } from '@/routes/notifications';
import type { NotificationItem as Item } from '@/types';

const icons: Record<string, LucideIcon> = {
    critical_stock: TriangleAlert,
    replenishment_digest: ClipboardList,
    forecast_run: TrendingUp,
    import_errors: FileWarning,
};

/** What a notification looks like: its icon, text, age and unread dot. */
export function NotificationContent({ item }: { item: Item }) {
    const Icon = icons[item.type] ?? Bell;

    return (
        <>
            <Icon className="mt-0.5 size-4 shrink-0 text-muted-foreground" />
            <span className="min-w-0 flex-1">
                <span
                    className={cn('block', !item.read && 'font-medium')}
                    data-test="notification-title"
                >
                    {item.title}
                </span>
                <span className="line-clamp-2 block text-muted-foreground">
                    {item.message}
                </span>
                <span className="block text-xs text-muted-foreground">
                    {timeAgo(item.created_at)}
                </span>
            </span>
            {!item.read && (
                <span
                    className="mt-1.5 size-2.5 shrink-0 rounded-full border-2 border-ink bg-primary dark:border-transparent"
                    role="img"
                    aria-label="Unread"
                    data-test="unread-dot"
                />
            )}
        </>
    );
}

type Props = {
    item: Item;
    className?: string;
};

/**
 * A notification as a link. Following it marks it read and goes where it
 * points, so it works as a plain link.
 */
export default function NotificationItem({ item, className }: Props) {
    return (
        <Link
            href={open(item.id)}
            className={cn('flex items-start gap-3 text-sm', className)}
            data-test={`notification-${item.id}`}
        >
            <NotificationContent item={item} />
        </Link>
    );
}
