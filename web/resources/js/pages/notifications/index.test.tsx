import { render, screen, within } from '@testing-library/react';
import userEvent from '@testing-library/user-event';
import type { ReactNode } from 'react';
import { afterEach, describe, expect, it, vi } from 'vitest';
import NotificationsIndex from '@/pages/notifications/index';
import { makeNotification } from '@/test/replenishment';
import type { NotificationItem, Paginated } from '@/types';

const inertia = vi.hoisted(() => ({ post: vi.fn() }));

vi.mock('@inertiajs/react', () => ({
    Head: () => null,
    router: { post: inertia.post },
    Link: ({
        href,
        children,
        ...rest
    }: {
        href: string | { url: string };
        children: ReactNode;
    }) => (
        <a href={typeof href === 'string' ? href : href.url} {...rest}>
            {children}
        </a>
    ),
}));

function page(data: NotificationItem[]): Paginated<NotificationItem> {
    return {
        data,
        current_page: 1,
        last_page: 1,
        from: data.length > 0 ? 1 : null,
        to: data.length > 0 ? data.length : null,
        total: data.length,
        prev_page_url: null,
        next_page_url: null,
    };
}

describe('Notifications page', () => {
    afterEach(() => vi.clearAllMocks());

    it('says when there is nothing yet', () => {
        render(<NotificationsIndex notifications={page([])} />);

        expect(screen.getByTestId('no-notifications').textContent).toContain(
            'No notifications yet.',
        );
        expect(screen.queryByTestId('mark-all-read')).toBeNull();
    });

    it('lists them, each opening through the server', () => {
        render(
            <NotificationsIndex
                notifications={page([
                    makeNotification({ id: 'a' }),
                    makeNotification({ id: 'b', read: true }),
                ])}
            />,
        );

        expect(screen.getAllByTestId('notification-row')).toHaveLength(2);
        expect(screen.getByTestId('notification-a').getAttribute('href')).toBe(
            '/notifications/a/open',
        );
        expect(screen.getByTestId('notification-b').getAttribute('href')).toBe(
            '/notifications/b/open',
        );
    });

    it('offers to mark only the unread ones read', async () => {
        render(
            <NotificationsIndex
                notifications={page([
                    makeNotification({ id: 'a' }),
                    makeNotification({ id: 'b', read: true }),
                ])}
            />,
        );

        const rows = screen.getAllByTestId('notification-row');

        expect(within(rows[0]).getByTestId('mark-read')).toBeTruthy();
        expect(within(rows[1]).queryByTestId('mark-read')).toBeNull();

        await userEvent.setup().click(within(rows[0]).getByTestId('mark-read'));

        expect(inertia.post).toHaveBeenCalledWith(
            '/notifications/a/read',
            {},
            { preserveScroll: true },
        );
    });

    it('marks everything read', async () => {
        render(
            <NotificationsIndex notifications={page([makeNotification()])} />,
        );

        await userEvent.setup().click(screen.getByTestId('mark-all-read'));

        expect(inertia.post).toHaveBeenCalledWith(
            '/notifications/read-all',
            {},
            { preserveScroll: true },
        );
    });

    it('offers no mark-all when everything has been read', () => {
        render(
            <NotificationsIndex
                notifications={page([makeNotification({ read: true })])}
            />,
        );

        expect(screen.queryByTestId('mark-all-read')).toBeNull();
    });

    it('links to the settings', () => {
        render(<NotificationsIndex notifications={page([])} />);

        expect(
            screen.getByTestId('notification-settings').getAttribute('href'),
        ).toBe('/settings/notifications');
    });
});
