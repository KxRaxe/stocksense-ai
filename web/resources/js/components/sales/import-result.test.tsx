import { render, screen } from '@testing-library/react';
import userEvent from '@testing-library/user-event';
import type { ReactNode } from 'react';
import { afterEach, describe, expect, it, vi } from 'vitest';
import ImportResult from '@/components/sales/import-result';
import type { ImportBatch } from '@/types';

const calls = vi.hoisted(() => ({ post: vi.fn() }));

vi.mock('@inertiajs/react', () => ({
    router: { post: calls.post },
    // Like the real Link: accepts a route object and passes other attributes through.
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

vi.mock('sonner', () => ({ toast: { error: vi.fn() } }));

const completed: ImportBatch = {
    id: 12,
    filename: 'march.csv',
    status: 'completed',
    status_label: 'Completed',
    is_active: false,
    can_undo: true,
    rows_total: 100,
    rows_processed: 100,
    rows_ok: 90,
    rows_duplicate: 6,
    rows_failed: 4,
    errors: [
        { row: 7, messages: ["Unknown SKU 'ZZ-1'."] },
        { row: 9, messages: ['Date is missing.', 'Quantity is missing.'] },
    ],
    has_error_report: true,
    error_message: null,
    adjust_stock: true,
    headers: ['date', 'sku', 'quantity'],
    columns: { date: 0, sku: 1, quantity: 2, unit_price: null },
    date_format: 'iso',
    user: 'Pat',
    created_at: null,
    finished_at: null,
};

const figure = (label: string) =>
    screen.getByText(label).parentElement?.textContent ?? '';

describe('ImportResult', () => {
    afterEach(() => vi.clearAllMocks());

    it('gives the counts', () => {
        render(<ImportResult batch={completed} />);

        expect(figure('Rows in the file')).toContain('100');
        expect(figure('Imported')).toContain('90');
        expect(figure('Already there, skipped')).toContain('6');
        expect(figure('Failed')).toContain('4');
    });

    it('lists the failed rows, with every problem in the row', () => {
        render(<ImportResult batch={completed} />);

        expect(screen.getByText("Unknown SKU 'ZZ-1'.")).toBeTruthy();
        expect(
            screen.getByText('Date is missing. Quantity is missing.'),
        ).toBeTruthy();
    });

    it('says when the list is only the first few and the report has the rest', () => {
        render(<ImportResult batch={completed} />);

        expect(
            screen.getByText(/first 2 of 4; the report has them all/),
        ).toBeTruthy();
    });

    it('links to the error report and to the imported sales', () => {
        render(<ImportResult batch={completed} />);

        expect(
            screen.getByTestId('download-error-report').getAttribute('href'),
        ).toBe('/sales/imports/12/errors');
        expect(
            screen.getByTestId('view-imported-sales').getAttribute('href'),
        ).toBe('/sales?import=12');
    });

    it('has no error report when nothing failed', () => {
        render(
            <ImportResult
                batch={{
                    ...completed,
                    rows_failed: 0,
                    errors: [],
                    has_error_report: false,
                }}
            />,
        );

        expect(screen.queryByTestId('download-error-report')).toBeNull();
        expect(screen.queryByText('Rows that failed')).toBeNull();
    });

    it('shows why an import failed', () => {
        render(
            <ImportResult
                batch={{
                    ...completed,
                    status: 'failed',
                    error_message:
                        'The import stopped because of an unexpected error.',
                }}
            />,
        );

        expect(screen.getByRole('alert').textContent).toContain(
            'The import stopped because of an unexpected error.',
        );
    });

    it('says so when an import has been undone, and offers no undo', () => {
        render(
            <ImportResult
                batch={{ ...completed, status: 'undone', can_undo: false }}
            />,
        );

        expect(screen.getByRole('status').textContent).toContain(
            'This import was undone',
        );
        expect(screen.queryByTestId('undo-import-button')).toBeNull();
        expect(screen.queryByTestId('view-imported-sales')).toBeNull();
    });

    describe('undoing', () => {
        it('asks first, saying what will happen', async () => {
            const user = userEvent.setup();
            render(<ImportResult batch={completed} />);

            await user.click(screen.getByTestId('undo-import-button'));

            expect(screen.getByText('Undo this import?')).toBeTruthy();
            expect(
                screen.getByText(
                    /removes the 90 sales it brought in and puts their stock back/,
                ),
            ).toBeTruthy();
            expect(calls.post).not.toHaveBeenCalled();
        });

        it('does not mention stock for an import that left it alone', async () => {
            const user = userEvent.setup();
            render(
                <ImportResult batch={{ ...completed, adjust_stock: false }} />,
            );

            await user.click(screen.getByTestId('undo-import-button'));

            expect(
                screen.getByText(/removes the 90 sales it brought in\./),
            ).toBeTruthy();
            expect(screen.queryByText(/puts their stock back/)).toBeNull();
        });

        it('undoes the import once confirmed', async () => {
            const user = userEvent.setup();
            render(<ImportResult batch={completed} />);

            await user.click(screen.getByTestId('undo-import-button'));
            await user.click(screen.getByTestId('confirm-undo-import'));

            expect(calls.post).toHaveBeenCalledWith(
                '/sales/imports/12/undo',
                {},
                expect.any(Object),
            );
        });

        it('is not offered once it can no longer be undone', () => {
            render(<ImportResult batch={{ ...completed, can_undo: false }} />);

            expect(screen.queryByTestId('undo-import-button')).toBeNull();
        });
    });
});
