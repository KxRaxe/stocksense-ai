import { Head, Link, router } from '@inertiajs/react';
import { Bell } from 'lucide-react';
import Heading from '@/components/heading';
import NotificationItem from '@/components/notifications/notification-item';
import Pagination from '@/components/pagination';
import { Button } from '@/components/ui/button';
import { index, read, readAll } from '@/routes/notifications';
import { edit as editPreferences } from '@/routes/notification-preferences';
import type { NotificationItem as Item, Paginated } from '@/types';

type Props = {
    notifications: Paginated<Item>;
};

export default function NotificationsIndex({ notifications }: Props) {
    const anyUnread = notifications.data.some((item) => !item.read);

    return (
        <>
            <Head title="Notifications" />

            <div className="space-y-6 p-4">
                <div className="flex flex-wrap items-start justify-between gap-4">
                    <Heading
                        title="Notifications"
                        description="Alerts and summaries sent to you. You can choose which ones you get, and whether they also come by email."
                    />
                    <div className="flex flex-wrap items-center gap-2">
                        {anyUnread && (
                            <Button
                                variant="outline"
                                onClick={() =>
                                    router.post(
                                        readAll.url(),
                                        {},
                                        { preserveScroll: true },
                                    )
                                }
                                data-test="mark-all-read"
                            >
                                Mark all as read
                            </Button>
                        )}
                        <Button variant="outline" asChild>
                            <Link
                                href={editPreferences()}
                                data-test="notification-settings"
                            >
                                Settings
                            </Link>
                        </Button>
                    </div>
                </div>

                {notifications.data.length === 0 ? (
                    <div
                        className="space-y-2 rounded-xl border-2 bg-card p-8 text-center shadow-brutal"
                        data-test="no-notifications"
                    >
                        <Bell className="mx-auto size-8 text-muted-foreground" />
                        <p className="font-medium">No notifications yet.</p>
                        <p className="text-sm text-muted-foreground">
                            Stock alerts, summaries and the results of forecasts
                            and imports will show up here.
                        </p>
                    </div>
                ) : (
                    <ul className="divide-y divide-border/20 rounded-xl border-2 bg-card shadow-brutal">
                        {notifications.data.map((item) => (
                            <li
                                key={item.id}
                                className="flex items-start gap-2 p-4"
                                data-test="notification-row"
                            >
                                <NotificationItem
                                    item={item}
                                    className="flex-1"
                                />
                                {!item.read && (
                                    <Button
                                        variant="ghost"
                                        size="sm"
                                        onClick={() =>
                                            router.post(
                                                read.url(item.id),
                                                {},
                                                { preserveScroll: true },
                                            )
                                        }
                                        data-test="mark-read"
                                    >
                                        Mark as read
                                    </Button>
                                )}
                            </li>
                        ))}
                    </ul>
                )}

                <Pagination paginator={notifications} />
            </div>
        </>
    );
}

NotificationsIndex.layout = {
    breadcrumbs: [{ title: 'Notifications', href: index() }],
};
