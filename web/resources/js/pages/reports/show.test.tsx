import { render, screen } from '@testing-library/react';
import type { ReactNode } from 'react';
import { describe, expect, it, vi } from 'vitest';
import ReportsIndex from '@/pages/reports/index';
import ReportShow from '@/pages/reports/show';
import { makeReport } from '@/test/reporting';

vi.mock('@inertiajs/react', () => ({
    Head: () => null,
    router: { get: vi.fn() },
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

describe('a report', () => {
    it('shows its name, what it is and what it covers', () => {
        render(<ReportShow {...makeReport()} />);

        expect(
            screen.getByText('Units and revenue for each product.'),
        ).toBeTruthy();
        expect(screen.getByTestId('report-subtitle').textContent).toBe(
            '6 Sep 2026 to 5 Oct 2026 · All categories',
        );
    });

    it('offers it as Excel and as PDF, with the filters carried over', () => {
        render(<ReportShow {...makeReport()} />);

        const excel = screen.getByTestId('export-xlsx');
        const pdf = screen.getByTestId('export-pdf');

        expect(excel.getAttribute('href')).toBe(
            '/reports/sales/export/xlsx?from=2026-09-06&to=2026-10-05',
        );
        expect(excel.hasAttribute('download')).toBe(true);
        expect(excel.textContent).toBe('Excel');
        expect(pdf.getAttribute('href')).toBe(
            '/reports/sales/export/pdf?from=2026-09-06&to=2026-10-05',
        );
        expect(pdf.textContent).toBe('PDF');
    });

    it('shows the headline figures', () => {
        render(<ReportShow {...makeReport()} />);

        const summary = screen.getByTestId('report-summary').textContent;

        expect(summary).toContain('Revenue');
        expect(summary).toContain('₱2,300.00');
        expect(summary).toContain('Best seller');
        expect(summary).toContain('Rice');
    });

    it('shows the table with its totals', () => {
        render(<ReportShow {...makeReport()} />);

        expect(screen.getAllByTestId('report-row')).toHaveLength(2);
        expect(screen.getByTestId('report-totals').textContent).toContain(
            '2,300',
        );
    });

    it('holds the totals back until the last page', () => {
        const report = makeReport();

        render(
            <ReportShow
                {...makeReport({
                    rows: { ...report.rows, current_page: 1, last_page: 3 },
                })}
            />,
        );

        expect(screen.queryByTestId('report-totals')).toBeNull();
    });

    it('shows the notes needed to read it', () => {
        render(<ReportShow {...makeReport()} />);

        expect(screen.getByTestId('report-notes').textContent).toBe(
            'Read this first.',
        );
    });

    it('has no notes list when there are none', () => {
        render(<ReportShow {...makeReport({ notes: [] })} />);

        expect(screen.queryByTestId('report-notes')).toBeNull();
    });

    it('lets the person switch to another report', () => {
        render(<ReportShow {...makeReport()} />);

        expect(
            screen.getByTestId('report-tab-inventory').getAttribute('href'),
        ).toBe('/reports/inventory');
        expect(
            screen
                .getByTestId('report-tab-sales')
                .getAttribute('aria-selected'),
        ).toBe('true');
    });

    it('offers no switcher to someone who can open only one', () => {
        render(
            <ReportShow
                {...makeReport({ reports: [{ key: 'sales', title: 'Sales' }] })}
            />,
        );

        expect(screen.queryByTestId('report-tab-sales')).toBeNull();
    });

    it('shows the filters, with what was wrong in the address', () => {
        render(
            <ReportShow
                {...makeReport({
                    filter_errors: {
                        to: 'The end date cannot be before the start date.',
                    },
                })}
            />,
        );

        expect(screen.getByTestId('report-filters')).toBeTruthy();
        expect(
            screen.getByText('The end date cannot be before the start date.'),
        ).toBeTruthy();
    });

    it('shows how many rows there are and pages through them', () => {
        const report = makeReport();

        render(
            <ReportShow
                {...makeReport({
                    rows: {
                        ...report.rows,
                        total: 60,
                        from: 1,
                        to: 25,
                        last_page: 3,
                        next_page_url: '/reports/sales?page=2',
                    },
                })}
            />,
        );

        expect(screen.getByText('Showing 1-25 of 60')).toBeTruthy();
        expect(
            screen.getByRole('link', { name: 'Next' }).getAttribute('href'),
        ).toBe('/reports/sales?page=2');
    });
});

describe('the list of reports', () => {
    const reports = [
        {
            key: 'sales',
            title: 'Sales',
            description: 'Units and revenue for each product.',
            filters: ['date' as const, 'category' as const],
        },
        {
            key: 'inventory',
            title: 'Inventory status',
            description: 'Stock on hand for every product.',
            filters: ['category' as const],
        },
    ];

    it('lists each report with what it is and a link to open it', () => {
        render(<ReportsIndex reports={reports} />);

        const sales = screen.getByTestId('report-sales');

        expect(sales.getAttribute('href')).toBe('/reports/sales');
        expect(sales.textContent).toContain('Sales');
        expect(sales.textContent).toContain(
            'Units and revenue for each product.',
        );
        expect(
            screen.getByTestId('report-inventory').getAttribute('href'),
        ).toBe('/reports/inventory');
    });

    it('says which filters each understands, and that one with no period is as of now', () => {
        render(<ReportsIndex reports={reports} />);

        expect(screen.getByTestId('report-sales').textContent).toContain(
            'Filter by period, category.',
        );
        expect(screen.getByTestId('report-sales').textContent).not.toContain(
            'as of now',
        );
        expect(screen.getByTestId('report-inventory').textContent).toContain(
            'Filter by category. Always as of now.',
        );
    });
});
