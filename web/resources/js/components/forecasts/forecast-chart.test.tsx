import { render, screen } from '@testing-library/react';
import { describe, expect, it } from 'vitest';
import ForecastChart, {
    buildChartData,
} from '@/components/forecasts/forecast-chart';
import type { ForecastPoint, HistoryPoint } from '@/types';

const history: HistoryPoint[] = [
    { period: '2026-09-07', qty: 12 },
    { period: '2026-09-14', qty: 9 },
    { period: '2026-09-21', qty: 15 },
];

const forecast: ForecastPoint[] = [
    { period: '2026-09-28', yhat: 14, lower: 8, upper: 20 },
    { period: '2026-10-05', yhat: 16, lower: 9, upper: 23 },
];

describe('buildChartData', () => {
    it('puts past sales first, then the forecast, in order', () => {
        const rows = buildChartData(history, forecast);

        expect(rows.map((row) => row.period)).toEqual([
            '2026-09-07',
            '2026-09-14',
            '2026-09-21',
            '2026-09-28',
            '2026-10-05',
        ]);
    });

    it('gives sold units to past periods and expected units with a range to future ones', () => {
        const rows = buildChartData(history, forecast);

        expect(rows[0]).toEqual({ period: '2026-09-07', actual: 12 });
        expect(rows[3]).toEqual({
            period: '2026-09-28',
            forecast: 14,
            band: [8, 20],
        });
    });

    it('joins the two lines by repeating the last actual value as the forecast line starts', () => {
        const rows = buildChartData(history, forecast);

        expect(rows[2]).toEqual({
            period: '2026-09-21',
            actual: 15,
            forecast: 15,
        });
        // That joining point has no range: the range is for what is expected.
        expect(rows[2].band).toBeUndefined();
    });

    it('does not invent a join when there is no forecast', () => {
        const rows = buildChartData(history, []);

        expect(rows).toHaveLength(3);
        expect(rows.every((row) => row.forecast === undefined)).toBe(true);
    });

    it('draws the forecast alone when there is no history', () => {
        const rows = buildChartData([], forecast);

        expect(rows).toHaveLength(2);
        expect(rows.every((row) => row.actual === undefined)).toBe(true);
    });

    it('is empty with nothing to show', () => {
        expect(buildChartData([], [])).toEqual([]);
    });

    it('keeps the numbers it was given', () => {
        const rows = buildChartData(
            [{ period: '2026-09-21', qty: 0 }],
            [{ period: '2026-09-28', yhat: 0, lower: 0, upper: 0 }],
        );

        // Zero is a real value, not a missing one.
        expect(rows[0].actual).toBe(0);
        expect(rows[1].band).toEqual([0, 0]);
    });
});

describe('ForecastChart', () => {
    it('explains what the lines and the band mean', () => {
        render(
            <ForecastChart
                history={history}
                forecast={forecast}
                granularity="week"
                unit="kg"
            />,
        );

        expect(screen.getByTestId('forecast-chart')).toBeTruthy();
        expect(screen.getByText('Sold (kg per week)')).toBeTruthy();
        expect(screen.getByText('Expected')).toBeTruthy();
        expect(screen.getByText('Likely range (8 times in 10)')).toBeTruthy();
    });

    it('says per month for a monthly chart', () => {
        render(
            <ForecastChart
                history={history}
                forecast={forecast}
                granularity="month"
                unit="box"
            />,
        );

        expect(screen.getByText('Sold (box per month)')).toBeTruthy();
    });
});
