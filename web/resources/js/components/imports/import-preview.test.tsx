import { render, screen, within } from '@testing-library/react';
import userEvent from '@testing-library/user-event';
import type { ReactNode } from 'react';
import { afterEach, describe, expect, it, vi } from 'vitest';
import ImportPreview from '@/components/imports/import-preview';
import type {
    ImportBatch,
    ImportField,
    ImportOption,
    ImportPreviewData,
} from '@/types';

// Whether the mocked Form reports unsaved changes, and any server errors.
const formState = vi.hoisted(() => ({
    isDirty: false,
    errors: {} as Record<string, string>,
}));

const calls = vi.hoisted(() => ({ post: vi.fn(), delete: vi.fn() }));

vi.mock('@inertiajs/react', () => ({
    Form: ({
        children,
        action,
    }: {
        children: (state: {
            processing: boolean;
            errors: Record<string, string>;
            isDirty: boolean;
        }) => ReactNode;
        action: string;
    }) => (
        <form action={action}>
            {children({
                processing: false,
                errors: formState.errors,
                isDirty: formState.isDirty,
            })}
        </form>
    ),
    router: { post: calls.post, delete: calls.delete },
}));

vi.mock('sonner', () => ({ toast: { error: vi.fn() } }));

const fields: ImportField[] = [
    { key: 'date', label: 'Date', required: true },
    { key: 'sku', label: 'SKU (product code)', required: true },
    { key: 'quantity', label: 'Quantity sold', required: true },
    { key: 'unit_price', label: 'Unit price', required: false },
];

const options: ImportOption[] = [
    {
        name: 'adjust_stock',
        label: 'Should these sales change stock levels?',
        kind: 'radio',
        upload: true,
        choices: [
            { value: '0', label: 'No, this is past sales history' },
            { value: '1', label: 'Yes, take them off the shelf' },
        ],
    },
    {
        name: 'date_format',
        label: 'How are dates written?',
        kind: 'select',
        upload: false,
        choices: [
            { value: 'iso', label: 'YYYY-MM-DD (2026-03-05)' },
            { value: 'mdy', label: 'MM/DD/YYYY (03/05/2026)' },
        ],
    },
];

const batch: ImportBatch = {
    id: 7,
    filename: 'march.csv',
    status: 'preview',
    status_label: 'Checking',
    is_active: false,
    can_undo: false,
    rows_total: 6,
    rows_processed: 0,
    rows_ok: 0,
    rows_duplicate: 0,
    rows_updated: 0,
    rows_failed: 0,
    errors: [],
    has_error_report: false,
    error_message: null,
    headers: ['Sale Date', 'Product Code', 'Qty', 'Price'],
    columns: { date: 0, sku: 1, quantity: 2, unit_price: 3 },
    options: { adjust_stock: '0', date_format: 'iso' },
    user: 'Pat',
    created_at: null,
    finished_at: null,
    links: {
        show: '/sales/imports/7',
        update: '/sales/imports/7',
        confirm: '/sales/imports/7/confirm',
        cancel: '/sales/imports/7',
        undo: '/sales/imports/7/undo',
        errors: '/sales/imports/7/errors',
        index: '/sales/imports',
    },
};

const preview: ImportPreviewData = {
    importable_rows: 3,
    import_label: 'Import 3 rows',
    figures: [
        { label: 'Will be imported', value: 3, tone: 'good' },
        { label: 'Already imported, skipped', value: 1, tone: 'neutral' },
        { label: 'Have a problem, skipped', value: 2, tone: 'warn' },
    ],
    notes: [],
    invalid_rows: 2,
    sample: [
        {
            row: 2,
            cells: ['2026-03-05', 'HW-001', 12, '85.00'],
            status: 'ok',
            label: 'OK',
            messages: [],
        },
        {
            row: 3,
            cells: ['2026-03-05', 'HW-001', 12, '85.00'],
            status: 'skip',
            label: 'Already imported',
            messages: [],
        },
        {
            row: 4,
            cells: ['2026-03-06', 'ZZ-9', 1, ''],
            status: 'error',
            label: '',
            messages: ["Unknown SKU 'ZZ-9'."],
        },
    ],
    problems: [
        { row: 4, messages: ["Unknown SKU 'ZZ-9'."] },
        { row: 5, messages: ['Date is missing.'] },
    ],
};

function setup(
    overrides: Partial<{ batch: ImportBatch; preview: ImportPreviewData }> = {},
) {
    render(
        <ImportPreview
            batch={overrides.batch ?? batch}
            preview={overrides.preview ?? preview}
            fields={fields}
            options={options}
        />,
    );

    return userEvent.setup();
}

const startButton = () =>
    screen.getByTestId('start-import-button') as HTMLButtonElement;

const figure = (label: string) =>
    screen.getByText(label).parentElement?.textContent ?? '';

describe('ImportPreview', () => {
    afterEach(() => {
        vi.clearAllMocks();
        formState.isDirty = false;
        formState.errors = {};
    });

    it('draws the figures the server gives, in order', () => {
        setup();

        expect(figure('Will be imported')).toContain('3');
        expect(figure('Already imported, skipped')).toContain('1');
        expect(figure('Have a problem, skipped')).toContain('2');
    });

    it('draws whatever figures another kind of import gives', () => {
        setup({
            preview: {
                ...preview,
                figures: [
                    { label: 'Will be created', value: 4, tone: 'good' },
                    { label: 'Will be updated', value: 2, tone: 'good' },
                    {
                        label: 'Have a problem, skipped',
                        value: 1,
                        tone: 'warn',
                    },
                ],
            },
        });

        expect(figure('Will be created')).toContain('4');
        expect(figure('Will be updated')).toContain('2');
        expect(screen.queryByText('Will be imported')).toBeNull();
    });

    it('lists the notes about what the import will do', () => {
        setup({
            preview: {
                ...preview,
                notes: [
                    '2 new categories will be created (Toys, Garden).',
                    '1 archived product will be brought back by the update.',
                ],
            },
        });

        const notes = within(screen.getByTestId('preview-notes'));

        expect(notes.getAllByRole('listitem')).toHaveLength(2);
        expect(
            notes.getByText('2 new categories will be created (Toys, Garden).'),
        ).toBeTruthy();
    });

    it('has no notes box when there is nothing to note', () => {
        setup();

        expect(screen.queryByTestId('preview-notes')).toBeNull();
    });

    it("offers the file's own headers for each field, with the guessed ones chosen", () => {
        setup();

        const date = screen.getByLabelText(/^Date/) as HTMLSelectElement;
        const price = screen.getByLabelText('Unit price') as HTMLSelectElement;

        expect([...date.options].map((o) => o.text)).toEqual([
            'Choose a column',
            'Sale Date',
            'Product Code',
            'Qty',
            'Price',
        ]);
        expect(date.value).toBe('0');
        expect(price.value).toBe('3');
        expect(price.options[0].text).toBe('Not in my file');
    });

    it('posts the changes to the address the server gave', () => {
        setup();

        expect(document.querySelector('form')?.getAttribute('action')).toBe(
            '/sales/imports/7',
        );
    });

    it("shows the saved choices, whatever the kind of import's questions are", () => {
        setup({
            batch: {
                ...batch,
                options: { adjust_stock: '1', date_format: 'mdy' },
            },
        });

        expect(
            (
                screen.getByLabelText(
                    'How are dates written?',
                ) as HTMLSelectElement
            ).value,
        ).toBe('mdy');
        expect(
            (
                screen.getByLabelText(
                    'Yes, take them off the shelf',
                ) as HTMLInputElement
            ).checked,
        ).toBe(true);
    });

    it('lists the problems found, with row numbers', () => {
        setup();

        const problems = within(screen.getByTestId('preview-problems'));

        expect(
            problems.getByText(/Row 4:/).parentElement?.textContent,
        ).toContain("Unknown SKU 'ZZ-9'.");
        expect(
            problems.getByText(/Row 5:/).parentElement?.textContent,
        ).toContain('Date is missing.');
    });

    it('says when only the first problems are listed', () => {
        setup({ preview: { ...preview, invalid_rows: 130 } });

        expect(
            screen.getByText('Problems found (first 2 of 130)'),
        ).toBeTruthy();
    });

    it('marks each sample row with the words the server gives it, or its problem', () => {
        setup();

        expect(
            within(screen.getByTestId('sample-row-2')).getByText('OK'),
        ).toBeTruthy();
        expect(
            within(screen.getByTestId('sample-row-3')).getByText(
                'Already imported',
            ),
        ).toBeTruthy();
        expect(
            within(screen.getByTestId('sample-row-4')).getByText(
                "Unknown SKU 'ZZ-9'.",
            ),
        ).toBeTruthy();
    });

    it('uses other labels for other kinds of import', () => {
        setup({
            preview: {
                ...preview,
                sample: [
                    {
                        row: 2,
                        cells: ['NEW-1', 'Nails', 'Hardware'],
                        status: 'ok',
                        label: 'New product',
                        messages: [],
                    },
                    {
                        row: 3,
                        cells: ['HW-001', 'Cement', 'Hardware'],
                        status: 'skip',
                        label: 'Already exists',
                        messages: [],
                    },
                ],
            },
        });

        expect(
            within(screen.getByTestId('sample-row-2')).getByText('New product'),
        ).toBeTruthy();
        expect(
            within(screen.getByTestId('sample-row-3')).getByText(
                'Already exists',
            ),
        ).toBeTruthy();
    });

    describe('the Import button', () => {
        it('uses the text the server gives it and starts the import', async () => {
            const user = setup();

            expect(startButton().textContent).toBe('Import 3 rows');
            expect(startButton().disabled).toBe(false);

            await user.click(startButton());

            expect(calls.post).toHaveBeenCalledWith(
                '/sales/imports/7/confirm',
                {},
                expect.any(Object),
            );
        });

        it('follows the server for the singular too', () => {
            setup({
                preview: {
                    ...preview,
                    importable_rows: 1,
                    import_label: 'Import 1 row',
                },
            });

            expect(startButton().textContent).toBe('Import 1 row');
        });

        it('is off when there is nothing to import', () => {
            setup({ preview: { ...preview, importable_rows: 0 } });

            expect(startButton().disabled).toBe(true);
        });

        it('is off, with a reason, while a required column is not chosen', () => {
            setup({
                batch: { ...batch, columns: { ...batch.columns, sku: null } },
            });

            expect(startButton().disabled).toBe(true);
            expect(
                screen.getByText(
                    /Choose a column for SKU \(product code\) before importing/,
                ),
            ).toBeTruthy();
        });

        it('is off while the settings have unsaved changes', () => {
            formState.isDirty = true;
            setup();

            expect(startButton().disabled).toBe(true);
            expect(
                screen.getByText(
                    /Update the preview to see the effect of your changes/,
                ),
            ).toBeTruthy();
        });
    });

    describe('Update preview', () => {
        it('is off until something has changed', () => {
            setup();

            expect(
                (
                    screen.getByTestId(
                        'update-preview-button',
                    ) as HTMLButtonElement
                ).disabled,
            ).toBe(true);
        });

        it('is on once the settings change', () => {
            formState.isDirty = true;
            setup();

            expect(
                (
                    screen.getByTestId(
                        'update-preview-button',
                    ) as HTMLButtonElement
                ).disabled,
            ).toBe(false);
        });
    });

    it('discards the file', async () => {
        const user = setup();

        await user.click(screen.getByTestId('cancel-import-button'));

        expect(calls.delete).toHaveBeenCalledWith(
            '/sales/imports/7',
            expect.any(Object),
        );
    });
});
