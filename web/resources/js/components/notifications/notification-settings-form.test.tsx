import { render, screen } from '@testing-library/react';
import userEvent from '@testing-library/user-event';
import { afterEach, describe, expect, it, vi } from 'vitest';
import NotificationSettingsForm from '@/components/notifications/notification-settings-form';
import type { FrequencyOption, NotificationSetting } from '@/types';

const form = vi.hoisted(() => ({
    errors: {} as Record<string, string>,
    processing: false,
    put: vi.fn(),
}));

// A working stand-in for Inertia's useForm: it keeps the data, and `put`
// records the data it would have sent.
vi.mock('@inertiajs/react', async () => {
    const { useState } = await import('react');

    return {
        useForm: (initial: Record<string, unknown>) => {
            const [data, setState] = useState(initial);

            return {
                data,
                setData: (key: string, value: unknown) =>
                    setState((current) => ({ ...current, [key]: value })),
                put: (url: string, options: unknown) =>
                    form.put(url, options, data),
                errors: form.errors,
                processing: form.processing,
            };
        },
    };
});

const types: NotificationSetting[] = [
    {
        type: 'critical_stock',
        label: 'Critical stock alerts',
        description: 'When products may run out.',
        is_digest: false,
        mail: true,
        database: true,
        digest: null,
    },
    {
        type: 'replenishment_digest',
        label: 'Replenishment digest',
        description: 'A summary of what needs ordering.',
        is_digest: true,
        mail: true,
        database: false,
        digest: 'weekly',
    },
];

const frequencies: FrequencyOption[] = [
    { value: 'daily', label: 'Every day' },
    { value: 'weekly', label: 'Every Monday' },
    { value: 'off', label: 'Never' },
];

const renderForm = () =>
    render(
        <NotificationSettingsForm types={types} frequencies={frequencies} />,
    );

const checkbox = (name: string) => screen.getByTestId(name);

describe('NotificationSettingsForm', () => {
    afterEach(() => {
        form.errors = {};
        form.processing = false;
        vi.clearAllMocks();
    });

    it('has a block for each kind of notification with its description', () => {
        renderForm();

        expect(screen.getByText('Critical stock alerts')).toBeTruthy();
        expect(screen.getByText('When products may run out.')).toBeTruthy();
        expect(screen.getByText('Replenishment digest')).toBeTruthy();
    });

    it('starts from what is saved', () => {
        renderForm();

        expect(
            checkbox('critical_stock-database').getAttribute('aria-checked'),
        ).toBe('true');
        expect(
            checkbox('critical_stock-mail').getAttribute('aria-checked'),
        ).toBe('true');
        expect(
            checkbox('replenishment_digest-database').getAttribute(
                'aria-checked',
            ),
        ).toBe('false');
        expect(
            checkbox('replenishment_digest-mail').getAttribute('aria-checked'),
        ).toBe('true');
    });

    it('asks how often only for the digest', () => {
        renderForm();

        expect(screen.queryByTestId('critical_stock-digest')).toBeNull();

        const how = screen.getByTestId(
            'replenishment_digest-digest',
        ) as HTMLSelectElement;

        expect(how.value).toBe('weekly');
        expect(Array.from(how.options).map((option) => option.text)).toEqual([
            'Every day',
            'Every Monday',
            'Never',
        ]);
    });

    it('sends what was chosen, as one set', async () => {
        const user = userEvent.setup();

        renderForm();

        await user.click(
            screen.getByLabelText('By email', {
                selector: '#critical_stock-mail',
            }),
        );
        await user.click(
            screen.getByLabelText('In the app', {
                selector: '#replenishment_digest-database',
            }),
        );
        await user.selectOptions(
            screen.getByTestId('replenishment_digest-digest'),
            'daily',
        );
        await user.click(screen.getByRole('button', { name: 'Save' }));

        expect(form.put).toHaveBeenCalledTimes(1);

        const [url, options, data] = form.put.mock.calls[0];

        expect(url).toBe('/settings/notifications');
        expect(options).toEqual({ preserveScroll: true });
        expect(data).toEqual({
            settings: {
                critical_stock: { mail: false, database: true, digest: null },
                replenishment_digest: {
                    mail: true,
                    database: true,
                    digest: 'daily',
                },
            },
        });
    });

    it('sends the saved values untouched when nothing was changed', async () => {
        renderForm();

        await userEvent
            .setup()
            .click(screen.getByRole('button', { name: 'Save' }));

        expect(form.put.mock.calls[0][2]).toEqual({
            settings: {
                critical_stock: { mail: true, database: true, digest: null },
                replenishment_digest: {
                    mail: true,
                    database: false,
                    digest: 'weekly',
                },
            },
        });
    });

    it('disables saving while it is being saved', () => {
        form.processing = true;

        renderForm();

        expect(
            (screen.getByTestId('save-settings') as HTMLButtonElement).disabled,
        ).toBe(true);
    });

    it('shows what the server refused', () => {
        form.errors = {
            'settings.replenishment_digest.digest':
                'The selected digest is invalid.',
        };

        renderForm();

        expect(screen.getByTestId('settings-error').textContent).toBe(
            'The selected digest is invalid.',
        );
        expect(
            screen.getAllByText('The selected digest is invalid.'),
        ).toHaveLength(2);
    });

    it('promises that emails carry no personal data', () => {
        renderForm();

        expect(
            screen.getByText(/never contain your name or email address/i),
        ).toBeTruthy();
    });
});
