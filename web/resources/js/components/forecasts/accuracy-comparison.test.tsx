import { render, screen, within } from '@testing-library/react';
import { describe, expect, it } from 'vitest';
import AccuracyComparison from '@/components/forecasts/accuracy-comparison';
import type { BaselineMetrics, MetricSet } from '@/types';

const model: MetricSet = {
    mae: 9.5,
    rmse: 16,
    mape: 26.1,
    wape: 16.3,
    n: 1080,
    coverage: 70.3,
};

const baselines: BaselineMetrics = {
    seasonal_naive: {
        mae: 11.7,
        rmse: 19.5,
        mape: 31.3,
        wape: 19.7,
        n: 1080,
        coverage: null,
    },
    moving_average: {
        mae: 10.9,
        rmse: 17.8,
        mape: 32.4,
        wape: 18.4,
        n: 1080,
        coverage: null,
    },
};

const cell = (row: string, column: string) =>
    screen.getByTestId(`${row}-${column}`);

describe('AccuracyComparison', () => {
    it('sets the model beside the two yardsticks', () => {
        render(
            <AccuracyComparison
                metrics={model}
                baselines={baselines}
                granularity="week"
            />,
        );

        expect(screen.getByText('Forecasting model')).toBeTruthy();
        expect(screen.getByText('Same week last year')).toBeTruthy();
        expect(screen.getByText('Recent average')).toBeTruthy();
    });

    it('says month for a monthly run', () => {
        render(
            <AccuracyComparison
                metrics={model}
                baselines={baselines}
                granularity="month"
            />,
        );

        expect(screen.getByText('Same month last year')).toBeTruthy();
    });

    it('shows each measure for each method', () => {
        render(
            <AccuracyComparison
                metrics={model}
                baselines={baselines}
                granularity="week"
            />,
        );

        expect(cell('wape', 'model').textContent).toContain('16.3%');
        expect(cell('wape', 'seasonal_naive').textContent).toContain('19.7%');
        expect(cell('wape', 'moving_average').textContent).toContain('18.4%');
        expect(cell('mape', 'model').textContent).toContain('26.1%');
        expect(cell('mae', 'model').textContent).toContain('9.5');
        expect(cell('rmse', 'seasonal_naive').textContent).toContain('19.5');
    });

    it('marks the best figure on each row', () => {
        render(
            <AccuracyComparison
                metrics={model}
                baselines={baselines}
                granularity="week"
            />,
        );

        // The model is best on every row here.
        for (const row of ['wape', 'mape', 'mae', 'rmse']) {
            expect(within(cell(row, 'model')).getByText('Best')).toBeTruthy();
            expect(
                within(cell(row, 'seasonal_naive')).queryByText('Best'),
            ).toBeNull();
        }
    });

    it('marks a yardstick when it does better', () => {
        render(
            <AccuracyComparison
                metrics={{ ...model, wape: 25 }}
                baselines={baselines}
                granularity="week"
            />,
        );

        expect(
            within(cell('wape', 'moving_average')).getByText('Best'),
        ).toBeTruthy();
        expect(within(cell('wape', 'model')).queryByText('Best')).toBeNull();
        // The other rows are unchanged.
        expect(within(cell('mae', 'model')).getByText('Best')).toBeTruthy();
    });

    it('shows a dash for a measure that could not be worked out', () => {
        render(
            <AccuracyComparison
                metrics={{ ...model, mape: null }}
                baselines={{
                    seasonal_naive: { ...baselines.seasonal_naive, mape: null },
                    moving_average: { ...baselines.moving_average, mape: null },
                }}
                granularity="week"
            />,
        );

        expect(cell('mape', 'model').textContent).toContain('-');
        // With nothing to compare, nobody is best.
        expect(within(cell('mape', 'model')).queryByText('Best')).toBeNull();
    });

    it('says how much it was measured on, and how often the range held', () => {
        render(
            <AccuracyComparison
                metrics={model}
                baselines={baselines}
                granularity="week"
            />,
        );

        const note = screen.getByText(/Measured on 1,080 product-weeks/);

        expect(note.textContent).toContain('inside the likely range 70.3%');
        expect(note.textContent).toContain('the target is 80%');
    });

    it('leaves the range out when there was none to check', () => {
        render(
            <AccuracyComparison
                metrics={{ ...model, coverage: null }}
                baselines={baselines}
                granularity="week"
            />,
        );

        expect(screen.queryByText(/likely range/)).toBeNull();
    });

    it('explains each measure in plain words', () => {
        render(
            <AccuracyComparison
                metrics={model}
                baselines={baselines}
                granularity="week"
            />,
        );

        expect(screen.getByText(/The fairest single number/)).toBeTruthy();
        expect(
            screen.getByText(
                /how many units the forecast is typically off by/i,
            ),
        ).toBeTruthy();
    });
});
