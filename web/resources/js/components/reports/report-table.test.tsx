import { render, screen, within } from '@testing-library/react';
import { describe, expect, it } from 'vitest';
import ReportTable from '@/components/reports/report-table';
import type { ReportColumnDef } from '@/types';

const columns: ReportColumnDef[] = [
    { key: 'name', label: 'Product', type: 'text' },
    { key: 'units', label: 'Units sold', type: 'integer' },
    { key: 'revenue', label: 'Revenue', type: 'money' },
    { key: 'when', label: 'Last sold', type: 'date' },
];

const rows = [
    { name: 'Rice', units: 1250, revenue: 1500.5, when: '2026-10-05' },
    { name: 'Nails', units: 1, revenue: null, when: null },
];

const totals = { name: 'Total', units: 1251 };

describe('ReportTable', () => {
    it('has a heading for every column', () => {
        render(
            <ReportTable
                columns={columns}
                rows={rows}
                totals={null}
                showTotals
            />,
        );

        expect(
            screen
                .getAllByRole('columnheader')
                .map((heading) => heading.textContent),
        ).toEqual(['Product', 'Units sold', 'Revenue', 'Last sold']);
    });

    it('writes each value by its kind, and a gap as a dash', () => {
        render(
            <ReportTable
                columns={columns}
                rows={rows}
                totals={null}
                showTotals
            />,
        );

        const [rice, nails] = screen.getAllByTestId('report-row');

        expect(
            within(rice)
                .getAllByRole('cell')
                .map((cell) => cell.textContent),
        ).toEqual(['Rice', '1,250', '₱1,500.50', 'Oct 5, 2026']);
        expect(
            within(nails)
                .getAllByRole('cell')
                .map((cell) => cell.textContent),
        ).toEqual(['Nails', '1', '-', '-']);
    });

    it('lines numbers up on the right and text on the left', () => {
        render(
            <ReportTable
                columns={columns}
                rows={rows}
                totals={null}
                showTotals
            />,
        );

        const heads = screen.getAllByRole('columnheader');
        const cells = within(
            screen.getAllByTestId('report-row')[0],
        ).getAllByRole('cell');

        expect(heads[0].className).not.toContain('text-right');
        expect(heads[1].className).toContain('text-right');
        expect(heads[2].className).toContain('text-right');
        expect(heads[3].className).not.toContain('text-right');
        expect(cells[1].className).toContain('text-right');
        expect(cells[0].className).not.toContain('text-right');
    });

    it('shows the totals under the last page, leaving blanks where there is no total', () => {
        render(
            <ReportTable
                columns={columns}
                rows={rows}
                totals={totals}
                showTotals
            />,
        );

        expect(
            within(screen.getByTestId('report-totals'))
                .getAllByRole('cell')
                .map((cell) => cell.textContent),
        ).toEqual(['Total', '1,251', '', '']);
    });

    it('keeps the totals for the last page', () => {
        render(
            <ReportTable
                columns={columns}
                rows={rows}
                totals={totals}
                showTotals={false}
            />,
        );

        expect(screen.queryByTestId('report-totals')).toBeNull();
    });

    it('has no totals row when there are none', () => {
        render(
            <ReportTable
                columns={columns}
                rows={rows}
                totals={null}
                showTotals
            />,
        );

        expect(screen.queryByTestId('report-totals')).toBeNull();
    });

    it('says so when there is nothing to show, without a totals row', () => {
        render(
            <ReportTable
                columns={columns}
                rows={[]}
                totals={totals}
                showTotals
            />,
        );

        expect(screen.getByTestId('no-rows').textContent).toBe(
            'Nothing to show for this period.',
        );
        expect(screen.queryByTestId('report-totals')).toBeNull();
    });
});
