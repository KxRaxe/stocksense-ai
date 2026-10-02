import { render, screen, within } from '@testing-library/react';
import userEvent from '@testing-library/user-event';
import type { ReactNode } from 'react';
import { afterEach, describe, expect, it, vi } from 'vitest';
import ImportPreview from '@/components/sales/import-preview';
import type { ImportBatch, ImportField, ImportPreviewData } from '@/types';

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

const dateFormats = [
    { value: 'iso', label: 'YYYY-MM-DD (2026-03-05)' },
    { value: 'mdy', label: 'MM/DD/YYYY (03/05/2026)' },
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
    rows_failed: 0,
    errors: [],
    has_error_report: false,
    error_message: null,
    adjust_stock: false,
    headers: ['Sale Date', 'Product Code', 'Qty', 'Price'],
    columns: { date: 0, sku: 1, quantity: 2, unit_price: 3 },
    date_format: 'iso',
    user: 'Pat',
    created_at: null,
    finished_at: null,
};

const preview: ImportPreviewData = {
    total_rows: 6,
    importable_rows: 3,
    duplicate_rows: 1,
    invalid_rows: 2,
    sample: [
        {
            row: 2,
            cells: ['2026-03-05', 'HW-001', 12, '85.00'],
            status: 'ok',
            messages: [],
        },
        {
            row: 3,
            cells: ['2026-03-05', 'HW-001', 12, '85.00'],
            status: 'duplicate',
            messages: [],
        },
        {
            row: 4,
            cells: ['2026-03-06', 'ZZ-9', 1, ''],
            status: 'error',
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
            dateFormats={dateFormats}
        />,
    );

    return userEvent.setup();
}

const startButton = () =>
    screen.getByTestId('start-import-button') as HTMLButtonElement;

describe('ImportPreview', () => {
    afterEach(() => {
        vi.clearAllMocks();
        formState.isDirty = false;
        formState.errors = {};
    });

    it('says how many rows will be imported, skipped as repeats, and skipped as wrong', () => {
        setup();

        expect(
            screen.getByText('Will be imported').parentElement?.textContent,
        ).toContain('3');
        expect(
            screen.getByText('Already imported, skipped').parentElement
                ?.textContent,
        ).toContain('1');
        expect(
            screen.getByText('Have a problem, skipped').parentElement
                ?.textContent,
        ).toContain('2');
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

    it('shows the saved date layout and stock choice', () => {
        setup({ batch: { ...batch, date_format: 'mdy', adjust_stock: true } });

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

    it('marks each sample row OK, already imported, or with its problem', () => {
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

    describe('the Import button', () => {
        it('says how many rows it will import and starts the import', async () => {
            const user = setup();

            expect(startButton().textContent).toContain('Import 3 rows');
            expect(startButton().disabled).toBe(false);

            await user.click(startButton());

            expect(calls.post).toHaveBeenCalledWith(
                '/sales/imports/7/confirm',
                {},
                expect.any(Object),
            );
        });

        it('uses the singular for one row', () => {
            setup({ preview: { ...preview, importable_rows: 1 } });

            expect(startButton().textContent).toContain('Import 1 row');
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
