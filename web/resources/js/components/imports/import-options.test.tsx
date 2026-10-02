import { render, screen } from '@testing-library/react';
import { describe, expect, it } from 'vitest';
import ImportOptions from '@/components/imports/import-options';
import type { ImportOption } from '@/types';

const radio: ImportOption = {
    name: 'existing_skus',
    label: 'What about SKUs that are already in your catalogue?',
    kind: 'radio',
    upload: true,
    choices: [
        { value: 'skip', label: 'Skip them', help: 'Leave them as they are.' },
        { value: 'update', label: 'Update them', help: 'Match the file.' },
    ],
};

const checkbox: ImportOption = {
    name: 'create_categories',
    label: 'Categories',
    kind: 'checkbox',
    upload: true,
    choices: [
        {
            value: '1',
            label: "Create categories that don't exist yet",
            help: 'They start at a 95% service level.',
        },
    ],
};

const select: ImportOption = {
    name: 'date_format',
    label: 'How are dates written?',
    kind: 'select',
    upload: false,
    choices: [
        { value: 'iso', label: 'YYYY-MM-DD' },
        { value: 'mdy', label: 'MM/DD/YYYY' },
    ],
};

describe('ImportOptions', () => {
    describe('radio buttons', () => {
        it('select the first choice when there is no saved value', () => {
            render(<ImportOptions options={[radio]} />);

            expect(
                (screen.getByLabelText('Skip them') as HTMLInputElement)
                    .checked,
            ).toBe(true);
            expect(
                (screen.getByLabelText('Update them') as HTMLInputElement)
                    .checked,
            ).toBe(false);
        });

        it('select the saved value', () => {
            render(
                <ImportOptions
                    options={[radio]}
                    values={{ existing_skus: 'update' }}
                />,
            );

            expect(
                (screen.getByLabelText('Update them') as HTMLInputElement)
                    .checked,
            ).toBe(true);
        });

        it('are named after the option, so they post as ordinary values', () => {
            render(<ImportOptions options={[radio]} />);

            expect(
                (screen.getByLabelText('Skip them') as HTMLInputElement).name,
            ).toBe('existing_skus');
            expect(
                (screen.getByLabelText('Update them') as HTMLInputElement)
                    .value,
            ).toBe('update');
        });

        it('explain each choice, and are described by that explanation', () => {
            render(<ImportOptions options={[radio]} />);

            expect(screen.getByText('Leave them as they are.')).toBeTruthy();
            expect(
                screen
                    .getByLabelText('Skip them')
                    .getAttribute('aria-describedby'),
            ).toBe(
                screen.getByText('Leave them as they are.').getAttribute('id'),
            );
        });

        it('leave the explanations out when compact', () => {
            render(<ImportOptions options={[radio]} compact />);

            expect(screen.queryByText('Leave them as they are.')).toBeNull();
            expect(screen.getByLabelText('Skip them')).toBeTruthy();
        });
    });

    describe('tick boxes', () => {
        it('start unticked, and send a "0" when left that way', () => {
            const { container } = render(
                <ImportOptions options={[checkbox]} />,
            );

            expect(
                (
                    screen.getByLabelText(
                        "Create categories that don't exist yet",
                    ) as HTMLInputElement
                ).checked,
            ).toBe(false);
            expect(
                (
                    container.querySelector(
                        'input[type="hidden"][name="create_categories"]',
                    ) as HTMLInputElement
                ).value,
            ).toBe('0');
        });

        it('are ticked when the saved value is "1"', () => {
            render(
                <ImportOptions
                    options={[checkbox]}
                    values={{ create_categories: '1' }}
                />,
            );

            expect(
                (
                    screen.getByLabelText(
                        "Create categories that don't exist yet",
                    ) as HTMLInputElement
                ).checked,
            ).toBe(true);
        });

        it('stay unticked when the saved value is "0"', () => {
            render(
                <ImportOptions
                    options={[checkbox]}
                    values={{ create_categories: '0' }}
                />,
            );

            expect(
                (
                    screen.getByLabelText(
                        "Create categories that don't exist yet",
                    ) as HTMLInputElement
                ).checked,
            ).toBe(false);
        });
    });

    describe('drop-downs', () => {
        it('offer every choice, starting on the saved one', () => {
            render(
                <ImportOptions
                    options={[select]}
                    values={{ date_format: 'mdy' }}
                />,
            );

            const dates = screen.getByLabelText(
                'How are dates written?',
            ) as HTMLSelectElement;

            expect([...dates.options].map((o) => o.text)).toEqual([
                'YYYY-MM-DD',
                'MM/DD/YYYY',
            ]);
            expect(dates.value).toBe('mdy');
            expect(dates.name).toBe('date_format');
        });

        it('start on the first choice with no saved value', () => {
            render(<ImportOptions options={[select]} />);

            expect(
                (
                    screen.getByLabelText(
                        'How are dates written?',
                    ) as HTMLSelectElement
                ).value,
            ).toBe('iso');
        });
    });

    it('shows the server’s message under the control it belongs to', () => {
        render(
            <ImportOptions
                options={[radio]}
                errors={{ existing_skus: 'The selected choice is invalid.' }}
            />,
        );

        expect(
            screen.getByText('The selected choice is invalid.'),
        ).toBeTruthy();
    });

    it('draws every option it is given, in order', () => {
        render(<ImportOptions options={[radio, checkbox, select]} />);

        const text = document.body.textContent ?? '';

        expect(text.indexOf('What about SKUs')).toBeLessThan(
            text.indexOf('Categories'),
        );
        expect(text.indexOf('Categories')).toBeLessThan(
            text.indexOf('How are dates written?'),
        );
    });
});
