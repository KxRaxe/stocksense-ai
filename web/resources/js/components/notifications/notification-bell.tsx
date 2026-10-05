import { Link, router, usePage } from '@inertiajs/react';
import { Bell } from 'lucide-react';
import { NotificationContent } from '@/components/notifications/notification-item';
import { Button } from '@/components/ui/button';
import {
    DropdownMenu,
    DropdownMenuContent,
    DropdownMenuItem,
    DropdownMenuLabel,
    DropdownMenuSeparator,
    DropdownMenuTrigger,
} from '@/components/ui/dropdown-menu';
import { index, open, readAll } from '@/routes/notifications';

/**
 * The bell in the page header: how many notifications are unread, and the
 * latest few. Shows nothing when nobody is signed in.
 */
export function NotificationBell() {
    const { bell } = usePage().props;

    if (!bell) {
        return null;
    }

    const { unread, recent } = bell;

    return (
        <DropdownMenu>
            <DropdownMenuTrigger asChild>
                <Button
                    variant="ghost"
                    size="icon"
                    className="relative"
                    aria-label={
                        unread > 0
                            ? `Notifications, ${unread} unread`
                            : 'Notifications'
                    }
                    data-test="notification-bell"
                >
                    <Bell />
                    {unread > 0 && (
                        <span
                            className="absolute -top-0.5 -right-0.5 flex h-4 min-w-4 items-center justify-center rounded-full border-2 border-ink bg-critical px-1 font-mono text-[10px] leading-none font-semibold text-ink"
                            data-test="unread-count"
                        >
                            {unread > 9 ? '9+' : unread}
                        </span>
                    )}
                </Button>
            </DropdownMenuTrigger>

            <DropdownMenuContent align="end" className="w-80 max-w-[90vw]">
                <div className="flex items-center justify-between pr-1">
                    <DropdownMenuLabel>Notifications</DropdownMenuLabel>
                    {unread > 0 && (
                        <Button
                            variant="link"
                            size="sm"
                            className="h-auto p-1 text-xs"
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
                </div>
                <DropdownMenuSeparator />

                {recent.length === 0 ? (
                    <p
                        className="px-2 py-6 text-center text-sm text-muted-foreground"
                        data-test="no-notifications"
                    >
                        Nothing yet.
                    </p>
                ) : (
                    <div className="max-h-96 overflow-y-auto">
                        {recent.map((item) => (
                            <DropdownMenuItem
                                key={item.id}
                                asChild
                                className="items-start"
                            >
                                <Link
                                    href={open(item.id)}
                                    data-test={`notification-${item.id}`}
                                >
                                    <NotificationContent item={item} />
                                </Link>
                            </DropdownMenuItem>
                        ))}
                    </div>
                )}

                <DropdownMenuSeparator />
                <DropdownMenuItem asChild className="justify-center">
                    <Link href={index()} data-test="see-all-notifications">
                        See all notifications
                    </Link>
                </DropdownMenuItem>
            </DropdownMenuContent>
        </DropdownMenu>
    );
}
