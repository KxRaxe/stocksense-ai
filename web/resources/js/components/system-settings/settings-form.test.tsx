import { render, screen, within } from '@testing-library/react';
import userEvent from '@testing-library/user-event';
import { afterEach, describe, expect, it, vi } from 'vitest';
import SettingsForm from '@/components/system-settings/settings-form';
import { makeSettingGroups } from '@/test/reporting';

const inertia = vi.hoisted(() => ({
    errors: {} as Record<string, string>,
    processing: false,
    put: vi.fn(),
    post: vi.fn(),
}));

// A working stand-in for Inertia's useForm: it keeps the data, and `put`
// records the data it would have sent.
vi.mock('@inertiajs/react', async () => {
    const { useState } = await import('react');

    return {
        router: { post: inertia.post },
        useForm: (initial: Record<string, unknown>) => {
            const [data, setState] = useState(initial);

            return {
                data,
                setData: (key: string, value: unknown) =>
                    setState((current) => ({ ...current, [key]: value })),
                put: (url: string, options: unknown) =>
                    inertia.put(url, options, data),
                errors: inertia.errors,
                processing: inertia.processing,
            };
        },
    };
});

function show(hasChanges = true) {
    return render(
        <SettingsForm
            groups={makeSettingGroups()}
            has_changes={hasChanges}
            time_zone="Asia/Manila"
        />,
    );
}

const input = (key: string) =>
    screen.getByTestId(`setting-${key}`) as HTMLInputElement;

describe('SettingsForm', () => {
    afterEach(() => {
        inertia.errors = {};
        inertia.processing = false;
        vi.clearAllMocks();
    });

    it('groups the settings, each with its name and what it does', () => {
        show();

        expect(screen.getByTestId('group-Stock advice')).toBeTruthy();
        expect(screen.getByTestId('group-Schedule')).toBeTruthy();
        expect(screen.getByLabelText('Review period')).toBeTruthy();
        expect(screen.getByText(/How often you place orders/)).toBeTruthy();
    });

    it('starts from what is saved, in a control that suits each kind of setting', () => {
        show();

        expect(input('review_days').value).toBe('14');
        expect(input('review_days').type).toBe('number');
        expect(input('review_days').min).toBe('1');
        expect(input('review_days').max).toBe('60');
        expect(input('review_days').step).toBe('1');
        expect(input('default_service_level').step).toBe('0.1');
        expect(input('digest_time').type).toBe('time');
        expect(input('digest_time').value).toBe('07:00');
        expect(
            (
                screen.getByTestId(
                    'setting-forecast_weekly_day',
                ) as unknown as HTMLSelectElement
            ).value,
        ).toBe('1');
        expect(
            screen
                .getByTestId('setting-forecast_schedule')
                .getAttribute('aria-checked'),
        ).toBe('true');
    });

    it('shows the unit beside a number', () => {
        show();

        expect(
            within(screen.getByTestId('field-review_days')).getByText('days'),
        ).toBeTruthy();
        expect(
            within(screen.getByTestId('field-default_service_level')).getByText(
                '%',
            ),
        ).toBeTruthy();
    });

    it('offers the choices for a choice', () => {
        show();

        expect(
            Array.from(
                (
                    screen.getByTestId(
                        'setting-forecast_weekly_day',
                    ) as unknown as HTMLSelectElement
                ).options,
            ).map((option) => option.text),
        ).toEqual(['Monday', 'Tuesday', 'Sunday']);
    });

    it('says what the default is only for a setting that has been changed', () => {
        show();

        expect(
            within(screen.getByTestId('field-review_days')).getByTestId(
                'default-note',
            ).textContent,
        ).toContain('Default: 7 days.');
        expect(
            within(
                screen.getByTestId('field-default_service_level'),
            ).queryByTestId('default-note'),
        ).toBeNull();
    });

    it('notices a change as it is made, and when it goes back', async () => {
        const user = userEvent.setup();

        show();

        const level = input('default_service_level');

        await user.clear(level);
        await user.type(level, '97');

        expect(
            within(
                screen.getByTestId('field-default_service_level'),
            ).getByTestId('default-note').textContent,
        ).toContain('Default: 95 %.');

        await user.clear(level);
        await user.type(level, '95');

        expect(
            within(
                screen.getByTestId('field-default_service_level'),
            ).queryByTestId('default-note'),
        ).toBeNull();
    });

    it('sends every setting as it now is', async () => {
        const user = userEvent.setup();

        show();

        const review = input('review_days');

        await user.clear(review);
        await user.type(review, '21');
        await user.click(screen.getByTestId('setting-forecast_schedule'));
        await user.selectOptions(
            screen.getByTestId('setting-forecast_weekly_day'),
            '0',
        );
        await user.click(screen.getByTestId('save-settings'));

        expect(inertia.put).toHaveBeenCalledTimes(1);

        const [url, options, data] = inertia.put.mock.calls[0];

        expect(url).toBe('/system-settings');
        expect(options).toEqual({ preserveScroll: true });
        expect(data).toEqual({
            values: {
                review_days: '21',
                default_service_level: 95,
                forecast_schedule: false,
                forecast_weekly_day: 0,
                digest_time: '07:00',
            },
        });
    });

    it('sends a changed time', async () => {
        show();

        const time = input('digest_time');

        // A time input takes its whole value at once.
        await userEvent.setup().clear(time);
        await userEvent.setup().type(time, '08:30');
        await userEvent.setup().click(screen.getByTestId('save-settings'));

        expect(inertia.put.mock.calls[0][2].values.digest_time).toBe('08:30');
    });

    it('disables saving while it is being saved', () => {
        inertia.processing = true;

        show();

        expect(
            (screen.getByTestId('save-settings') as HTMLButtonElement).disabled,
        ).toBe(true);
    });

    it('shows what the server refused, beside the setting', () => {
        inertia.errors = {
            'values.review_days': 'The review period must be at least 1.',
        };

        show();

        expect(
            within(screen.getByTestId('field-review_days')).getByText(
                'The review period must be at least 1.',
            ),
        ).toBeTruthy();
    });

    it("says the times are in the shop's time zone", () => {
        show();

        expect(screen.getByTestId('group-Schedule').textContent).toContain(
            'Asia/Manila',
        );
    });

    describe('restoring the defaults', () => {
        it('is offered only when something has been changed', () => {
            const { unmount } = show(true);

            expect(screen.getByTestId('restore-defaults')).toBeTruthy();

            unmount();
            show(false);

            expect(screen.queryByTestId('restore-defaults')).toBeNull();
        });

        it('asks first, and does nothing until told to', async () => {
            const user = userEvent.setup();

            show();
            await user.click(screen.getByTestId('restore-defaults'));

            expect(
                screen.getByText('Restore the default settings?'),
            ).toBeTruthy();
            expect(inertia.post).not.toHaveBeenCalled();
        });

        it('restores them once confirmed', async () => {
            const user = userEvent.setup();

            show();
            await user.click(screen.getByTestId('restore-defaults'));
            await user.click(screen.getByTestId('confirm-restore-defaults'));

            expect(inertia.post).toHaveBeenCalledWith(
                '/system-settings/reset',
                {},
                { preserveScroll: true },
            );
        });

        it('does nothing when it is cancelled', async () => {
            const user = userEvent.setup();

            show();
            await user.click(screen.getByTestId('restore-defaults'));
            await user.click(screen.getByRole('button', { name: 'Cancel' }));

            expect(inertia.post).not.toHaveBeenCalled();
            expect(
                screen.queryByText('Restore the default settings?'),
            ).toBeNull();
        });
    });
});
