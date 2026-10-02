import { render, screen } from '@testing-library/react';
import type { ReactNode } from 'react';
import { describe, expect, it, vi } from 'vitest';
import AccuracyTrend from '@/components/forecasts/accuracy-trend';
import CategoryAccuracyView from '@/components/forecasts/category-accuracy';
import FeatureBars from '@/components/forecasts/feature-bars';
import GranularityTabs from '@/components/forecasts/granularity-tabs';
import type { CategoryAccuracy, MetricSet } from '@/types';

vi.mock('@inertiajs/react', () => ({
    Link: ({
        href,
        children,
        ...rest
    }: {
        href: string;
        children: ReactNode;
    }) => (
        <a href={href} {...rest}>
            {children}
        </a>
    ),
}));

describe('GranularityTabs', () => {
    const options = [
        { value: 'week' as const, label: 'Weekly' },
        { value: 'month' as const, label: 'Monthly' },
    ];

    it('links to the same page at each granularity', () => {
        render(
            <GranularityTabs
                granularity="week"
                options={options}
                href={(value) => `/forecasts?granularity=${value}`}
            />,
        );

        expect(
            screen.getByTestId('granularity-week').getAttribute('href'),
        ).toBe('/forecasts?granularity=week');
        expect(
            screen.getByTestId('granularity-month').getAttribute('href'),
        ).toBe('/forecasts?granularity=month');
    });

    it('marks the current one as selected', () => {
        render(
            <GranularityTabs
                granularity="month"
                options={options}
                href={(value) => `/x?g=${value}`}
            />,
        );

        expect(
            screen
                .getByTestId('granularity-month')
                .getAttribute('aria-selected'),
        ).toBe('true');
        expect(
            screen
                .getByTestId('granularity-week')
                .getAttribute('aria-selected'),
        ).toBe('false');
    });
});

describe('FeatureBars', () => {
    it('describes each clue in plain words, with its share', () => {
        render(
            <FeatureBars
                granularity="week"
                features={[
                    { feature: 'roll_mean_4', importance: 0.4 },
                    { feature: 'lag_52', importance: 0.1 },
                ]}
            />,
        );

        expect(screen.getByText('Average of the last 4 weeks')).toBeTruthy();
        expect(screen.getByText('40.0%')).toBeTruthy();
        expect(screen.getByText('Sales at this time last year')).toBeTruthy();
        expect(screen.getByText('10.0%')).toBeTruthy();
    });

    it('scales the bars to the biggest', () => {
        const { container } = render(
            <FeatureBars
                granularity="week"
                features={[
                    { feature: 'roll_mean_4', importance: 0.4 },
                    { feature: 'lag_52', importance: 0.1 },
                ]}
            />,
        );

        const widths = [
            ...container.querySelectorAll<HTMLElement>(
                'li span[aria-hidden] > span',
            ),
        ].map((bar) => bar.style.width);

        expect(widths).toEqual(['100%', '25%']);
    });

    it('says the model has not been trained when there is nothing to show', () => {
        render(<FeatureBars granularity="week" features={[]} />);

        expect(
            screen.getByText('The model has not been trained yet.'),
        ).toBeTruthy();
    });
});

const scores = (wape: number): MetricSet => ({
    mae: 1,
    rmse: 1,
    mape: 1,
    wape,
    n: 10,
    coverage: null,
});

describe('CategoryAccuracyView', () => {
    const categories: CategoryAccuracy[] = [
        {
            name: 'Hardware',
            model: scores(15),
            seasonal_naive: scores(19),
            moving_average: scores(17),
        },
        {
            name: 'Food',
            model: scores(8.25),
            seasonal_naive: scores(10),
            moving_average: scores(9),
        },
    ];

    it('lists each category with the model and both yardsticks', () => {
        render(
            <CategoryAccuracyView categories={categories} granularity="week" />,
        );

        const hardware = screen.getByTestId('category-row-Hardware');

        expect(hardware.textContent).toContain('15.0%');
        expect(hardware.textContent).toContain('19.0%');
        expect(hardware.textContent).toContain('17.0%');
        expect(screen.getByTestId('category-row-Food').textContent).toContain(
            '8.3%',
        );
    });

    it('says when no category has enough history', () => {
        render(<CategoryAccuracyView categories={[]} granularity="week" />);

        expect(
            screen.getByText('No category has enough history to measure yet.'),
        ).toBeTruthy();
        expect(screen.queryByTestId('category-chart')).toBeNull();
    });

    it('has a chart as well as the table', () => {
        render(
            <CategoryAccuracyView
                categories={categories}
                granularity="month"
            />,
        );

        expect(
            screen.getByRole('img', { name: 'Forecast error by category' }),
        ).toBeTruthy();
    });
});

describe('AccuracyTrend', () => {
    const point = (run: number, model: number) => ({
        run,
        date: `2026-09-0${run}`,
        model,
        seasonal_naive: 20,
        moving_average: 18,
    });

    it('draws a chart once there are two runs', () => {
        render(
            <AccuracyTrend
                trend={[point(1, 17), point(2, 16)]}
                granularity="week"
            />,
        );

        expect(
            screen.getByRole('img', { name: 'Forecast error over time' }),
        ).toBeTruthy();
    });

    it('waits for a second run', () => {
        render(<AccuracyTrend trend={[point(1, 17)]} granularity="week" />);

        expect(
            screen.getByText(/once there have been two forecast runs/),
        ).toBeTruthy();
        expect(screen.queryByTestId('trend-chart')).toBeNull();
    });
});
