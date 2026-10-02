import { render, screen, within } from '@testing-library/react';
import userEvent from '@testing-library/user-event';
import type { ReactNode } from 'react';
import { afterEach, describe, expect, it, vi } from 'vitest';
import { NotificationBell } from '@/components/notifications/notification-bell';
import { makeNotification } from '@/test/replenishment';
import type { NotificationsShared } from '@/types';

const inertia = vi.hoisted(() => ({
    bell: null as unknown,
    post: vi.fn(),
}));

vi.mock('@inertiajs/react', () => ({
    usePage: () => ({ props: { bell: inertia.bell } }),
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

function signedIn(shared: NotificationsShared | null) {
    inertia.bell = shared;
}

const bell = () => screen.getByTestId('notification-bell');

describe('NotificationBell', () => {
    afterEach(() => {
        inertia.bell = null;
        vi.clearAllMocks();
    });

    it('is not there when nobody is signed in', () => {
        signedIn(null);

        const { container } = render(<NotificationBell />);

        expect(container.textContent).toBe('');
    });

    it('shows no count when everything has been read', () => {
        signedIn({ unread: 0, recent: [makeNotification({ read: true })] });

        render(<NotificationBell />);

        expect(screen.queryByTestId('unread-count')).toBeNull();
        expect(bell().getAttribute('aria-label')).toBe('Notifications');
    });

    it('shows how many are unread, and says so to a screen reader', () => {
        signedIn({ unread: 3, recent: [] });

        render(<NotificationBell />);

        expect(screen.getByTestId('unread-count').textContent).toBe('3');
        expect(bell().getAttribute('aria-label')).toBe(
            'Notifications, 3 unread',
        );
    });

    it('caps the count at 9+', () => {
        signedIn({ unread: 42, recent: [] });

        render(<NotificationBell />);

        expect(screen.getByTestId('unread-count').textContent).toBe('9+');
        expect(bell().getAttribute('aria-label')).toBe(
            'Notifications, 42 unread',
        );
    });

    it('lists the latest, each opening through the server so it is marked read', async () => {
        signedIn({
            unread: 1,
            recent: [
                makeNotification({ id: 'one' }),
                makeNotification({
                    id: 'two',
                    type: 'forecast_run',
                    title: 'Weekly forecast ready',
                    message: 'Typically off by 16.3%.',
                    read: true,
                }),
            ],
        });

        render(<NotificationBell />);
        await userEvent.setup().click(bell());

        const first = screen.getByTestId('notification-one');
        const second = screen.getByTestId('notification-two');

        expect(first.getAttribute('href')).toBe('/notifications/one/open');
        expect(second.getAttribute('href')).toBe('/notifications/two/open');
        expect(first.textContent).toContain(
            'Critical stock: 2 products may run out',
        );
        expect(first.textContent).toContain('Nails and Cement may run out.');
        expect(within(first).getByTestId('unread-dot')).toBeTruthy();
        expect(within(second).queryByTestId('unread-dot')).toBeNull();
    });

    it('says when there is nothing yet', async () => {
        signedIn({ unread: 0, recent: [] });

        render(<NotificationBell />);
        await userEvent.setup().click(bell());

        expect(screen.getByTestId('no-notifications').textContent).toBe(
            'Nothing yet.',
        );
    });

    it('links to the full list', async () => {
        signedIn({ unread: 0, recent: [] });

        render(<NotificationBell />);
        await userEvent.setup().click(bell());

        expect(
            screen.getByTestId('see-all-notifications').getAttribute('href'),
        ).toBe('/notifications');
    });

    it('marks everything read', async () => {
        signedIn({ unread: 2, recent: [makeNotification()] });

        render(<NotificationBell />);
        await userEvent.setup().click(bell());
        await userEvent.setup().click(screen.getByTestId('mark-all-read'));

        expect(inertia.post).toHaveBeenCalledWith(
            '/notifications/read-all',
            {},
            { preserveScroll: true },
        );
    });

    it('offers no mark-all when there is nothing unread', async () => {
        signedIn({ unread: 0, recent: [makeNotification({ read: true })] });

        render(<NotificationBell />);
        await userEvent.setup().click(bell());

        expect(screen.queryByTestId('mark-all-read')).toBeNull();
    });
});
