import { render, screen, within } from '@testing-library/react';
import userEvent from '@testing-library/user-event';
import type { ReactNode } from 'react';
import { afterEach, describe, expect, it, vi } from 'vitest';
import AuditLog from '@/pages/audit-log/index';
import { makeAuditEntry } from '@/test/reporting';
import type { AuditLogProps } from '@/types';

const inertia = vi.hoisted(() => ({ get: vi.fn() }));

vi.mock('@inertiajs/react', () => ({
    Head: () => null,
    router: { get: inertia.get },
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

function props(changes: Partial<AuditLogProps> = {}): AuditLogProps {
    const entries = [makeAuditEntry()];

    return {
        entries: {
            data: entries,
            current_page: 1,
            last_page: 1,
            from: 1,
            to: entries.length,
            total: entries.length,
            prev_page_url: null,
            next_page_url: null,
        },
        filters: { area: '', user: null, from: '', to: '', search: '' },
        areas: [
            { value: 'sales', label: 'Sales' },
            { value: 'settings', label: 'System settings' },
        ],
        users: [
            { id: 1, name: 'Olive Owner' },
            { id: 2, name: 'Pat Manager' },
        ],
        ...changes,
    };
}

describe('Audit log', () => {
    afterEach(() => vi.clearAllMocks());

    it('says who can see it, and that it cannot be changed', () => {
        render(<AuditLog {...props()} />);

        expect(
            screen.getByText(
                /Only the Owner can see this, and nothing here can be changed or deleted/,
            ),
        ).toBeTruthy();
    });

    it('lists each entry with when, who, the area and what happened', () => {
        render(<AuditLog {...props()} />);

        const row = screen.getByTestId('audit-entry-1');

        expect(row.textContent).toContain('Olive Owner');
        expect(row.textContent).toContain('System settings');
        expect(row.textContent).toContain('Changed the system settings');
        expect(row.textContent).toMatch(/2026/);
    });

    it('says the system did it when nobody was signed in', () => {
        render(
            <AuditLog
                {...props({
                    entries: {
                        ...props().entries,
                        data: [
                            makeAuditEntry({
                                who: null,
                                description: 'Weekly forecast started',
                            }),
                        ],
                    },
                })}
            />,
        );

        expect(screen.getByTestId('audit-entry-1').textContent).toContain(
            'The system',
        );
    });

    it('names the thing it was done to', () => {
        render(
            <AuditLog
                {...props({
                    entries: {
                        ...props().entries,
                        data: [makeAuditEntry({ subject: 'Product: Nails' })],
                    },
                })}
            />,
        );

        expect(screen.getByTestId('audit-entry-1').textContent).toContain(
            'Product: Nails',
        );
    });

    it('lists what changed, from and to, behind a disclosure', () => {
        render(<AuditLog {...props()} />);

        const details = screen.getByTestId('audit-details');

        expect(details.querySelector('summary')?.textContent).toBe('1 detail');
        expect(details.textContent).toContain('Review period:');
        expect(details.textContent).toContain('7');
        expect(details.textContent).toContain('to 14');
    });

    it('lists a new value without a from', () => {
        render(
            <AuditLog
                {...props({
                    entries: {
                        ...props().entries,
                        data: [
                            makeAuditEntry({
                                details: [
                                    { label: 'Name', from: null, to: 'Toys' },
                                    { label: 'Note', from: null, to: null },
                                ],
                            }),
                        ],
                    },
                })}
            />,
        );

        const details = screen.getByTestId('audit-details');

        expect(details.querySelector('summary')?.textContent).toBe('2 details');
        expect(details.textContent).toContain('Name: Toys');
        expect(details.textContent).not.toContain(' to Toys');
        expect(details.textContent).toContain('Note: -');
    });

    it('has no disclosure for an entry with nothing to add', () => {
        render(
            <AuditLog
                {...props({
                    entries: {
                        ...props().entries,
                        data: [makeAuditEntry({ details: [] })],
                    },
                })}
            />,
        );

        expect(screen.queryByTestId('audit-details')).toBeNull();
    });

    it('says so when nothing matches', () => {
        render(
            <AuditLog
                {...props({
                    entries: {
                        ...props().entries,
                        data: [],
                        total: 0,
                        from: null,
                        to: null,
                    },
                })}
            />,
        );

        expect(screen.getByTestId('no-entries').textContent).toBe(
            'Nothing matches.',
        );
    });

    describe('filters', () => {
        it('offers the areas and people that appear in the log', () => {
            render(<AuditLog {...props()} />);

            expect(
                Array.from(
                    (screen.getByTestId('audit-area') as HTMLSelectElement)
                        .options,
                ).map((option) => option.text),
            ).toEqual(['Everything', 'Sales', 'System settings']);
            expect(
                Array.from(
                    (screen.getByTestId('audit-user') as HTMLSelectElement)
                        .options,
                ).map((option) => option.text),
            ).toEqual(['Anyone', 'Olive Owner', 'Pat Manager']);
        });

        it('narrows by area straight away, keeping the other filters', async () => {
            render(
                <AuditLog
                    {...props({
                        filters: {
                            area: '',
                            user: 2,
                            from: '2026-10-01',
                            to: '',
                            search: '',
                        },
                    })}
                />,
            );

            await userEvent
                .setup()
                .selectOptions(screen.getByTestId('audit-area'), 'sales');

            expect(inertia.get).toHaveBeenCalledWith(
                '/audit-log',
                { area: 'sales', user: 2, from: '2026-10-01' },
                { preserveState: true, preserveScroll: true, replace: true },
            );
        });

        it('narrows by person', async () => {
            render(<AuditLog {...props()} />);

            await userEvent
                .setup()
                .selectOptions(screen.getByTestId('audit-user'), '2');

            expect(inertia.get.mock.calls[0][1]).toEqual({ user: '2' });
        });

        it('narrows by period', async () => {
            render(<AuditLog {...props()} />);

            const from = screen.getByTestId('audit-from');

            await userEvent.setup().type(from, '2026-09-20');

            expect(inertia.get).toHaveBeenCalled();
            expect(inertia.get.mock.calls.at(-1)?.[1]).toEqual({
                from: '2026-09-20',
            });
        });

        it('searches once typing pauses, and clears the filter when it is emptied', async () => {
            vi.useFakeTimers({ shouldAdvanceTime: true });

            const user = userEvent.setup({
                advanceTimers: vi.advanceTimersByTime,
            });

            render(<AuditLog {...props()} />);
            await user.type(screen.getByTestId('audit-search'), 'export');

            expect(inertia.get).not.toHaveBeenCalled();

            await vi.advanceTimersByTimeAsync(400);

            expect(inertia.get).toHaveBeenCalledTimes(1);
            expect(inertia.get.mock.calls[0][1]).toEqual({ search: 'export' });

            vi.useRealTimers();
        });

        it('starts from the filters in the address', () => {
            render(
                <AuditLog
                    {...props({
                        filters: {
                            area: 'sales',
                            user: 1,
                            from: '2026-09-01',
                            to: '2026-09-30',
                            search: 'sale',
                        },
                    })}
                />,
            );

            expect(
                (screen.getByTestId('audit-area') as HTMLSelectElement).value,
            ).toBe('sales');
            expect(
                (screen.getByTestId('audit-user') as HTMLSelectElement).value,
            ).toBe('1');
            expect(
                (screen.getByTestId('audit-from') as HTMLInputElement).value,
            ).toBe('2026-09-01');
            expect(
                (screen.getByTestId('audit-to') as HTMLInputElement).value,
            ).toBe('2026-09-30');
            expect(
                (screen.getByTestId('audit-search') as HTMLInputElement).value,
            ).toBe('sale');
        });
    });

    it('pages through a long log', () => {
        render(
            <AuditLog
                {...props({
                    entries: {
                        ...props().entries,
                        total: 60,
                        from: 1,
                        to: 25,
                        last_page: 3,
                        next_page_url: '/audit-log?page=2',
                    },
                })}
            />,
        );

        expect(screen.getByText('Showing 1-25 of 60')).toBeTruthy();
        expect(
            within(document.body)
                .getByRole('link', { name: 'Next' })
                .getAttribute('href'),
        ).toBe('/audit-log?page=2');
    });
});
