import { describe, expect, it } from 'vitest';
import {
    featureLabel,
    formatChange,
    formatPercent,
    formatRange,
    formatUnits,
    periodLabel,
    periodNoun,
    verdict,
} from '@/lib/forecast';

describe('periodLabel', () => {
    it('labels a week by the day it starts', () => {
        expect(periodLabel('2026-09-28', 'week')).toBe('Sep 28');
        expect(periodLabel('2026-01-05', 'week')).toBe('Jan 5');
    });

    it('labels a month by name and year', () => {
        expect(periodLabel('2026-10-01', 'month')).toBe('Oct 2026');
        expect(periodLabel('2027-01-01', 'month')).toBe('Jan 2027');
    });
});

describe('periodNoun', () => {
    it('pluralises unless there is exactly one', () => {
        expect(periodNoun('week', 1)).toBe('week');
        expect(periodNoun('week', 8)).toBe('weeks');
        expect(periodNoun('month', 3)).toBe('months');
        expect(periodNoun('month')).toBe('months');
    });
});

describe('number formats', () => {
    it('shows a percentage to one decimal, or a dash', () => {
        expect(formatPercent(16.2986)).toBe('16.3%');
        expect(formatPercent(0)).toBe('0.0%');
        expect(formatPercent(null)).toBe('-');
    });

    it('shows units to at most one decimal', () => {
        expect(formatUnits(12)).toBe('12');
        expect(formatUnits(12.46)).toBe('12.5');
        expect(formatUnits(1234.5)).toBe('1,234.5');
    });

    it('signs a change', () => {
        expect(formatChange(12)).toBe('+12.0%');
        expect(formatChange(-5.55)).toBe('-5.5%');
        expect(formatChange(0)).toBe('0.0%');
    });

    it('shows a forecast with its range', () => {
        expect(formatRange(30, 20, 40.4)).toBe('30 (20-40.4)');
    });
});

describe('featureLabel', () => {
    it('names the calendar clues in plain words', () => {
        expect(featureLabel('back_to_school', 'week')).toBe(
            'Back-to-school season (mid May to June)',
        );
        expect(featureLabel('paydays', 'month')).toContain('Paydays');
        expect(featureLabel('period_of_year', 'week')).toBe('Time of year');
    });

    it('explains rolling averages in the right unit', () => {
        expect(featureLabel('roll_mean_4', 'week')).toBe(
            'Average of the last 4 weeks',
        );
        expect(featureLabel('roll_mean_3', 'month')).toBe(
            'Average of the last 3 months',
        );
        expect(featureLabel('roll_std_8', 'week')).toBe(
            'How much sales varied over the last 8 weeks',
        );
    });

    it('explains lags, and treats a year back as last year', () => {
        expect(featureLabel('lag_1', 'week')).toBe('Sales in the week before');
        expect(featureLabel('lag_3', 'week')).toBe('Sales 3 weeks ago');
        expect(featureLabel('lag_52', 'week')).toBe(
            'Sales at this time last year',
        );
        expect(featureLabel('lag_12', 'month')).toBe(
            'Sales at this time last year',
        );
        expect(featureLabel('lag_12', 'week')).toBe('Sales 12 weeks ago');
    });

    it('falls back to the raw name for a feature it does not know', () => {
        expect(featureLabel('something_new', 'week')).toBe('something_new');
    });
});

describe('verdict', () => {
    const base = {
        model: 16,
        seasonal_naive: 20,
        moving_average: 18,
        coverage: 70,
    };

    it('says the model is closer than both when it is', () => {
        expect(verdict(base, 'week')).toBe(
            'On recent weeks, forecasts were off by 16.0% of units sold, closer than the same week last year and a recent average.',
        );
    });

    it('says which yardstick it beat and which it did not', () => {
        expect(verdict({ ...base, moving_average: 15 }, 'week')).toContain(
            'closer than the same week last year but not a recent average',
        );
        expect(verdict({ ...base, seasonal_naive: 14 }, 'month')).toContain(
            'closer than a recent average but not the same month last year',
        );
    });

    it('warns when it beats neither', () => {
        expect(
            verdict(
                { ...base, seasonal_naive: 10, moving_average: 12 },
                'week',
            ),
        ).toContain('no better than simpler guesses');
    });

    it('copes with a yardstick that could not be measured', () => {
        expect(verdict({ ...base, seasonal_naive: null }, 'week')).toContain(
            'closer than a recent average.',
        );
    });

    it('says so when nothing could be measured', () => {
        expect(verdict(null, 'week')).toBe(
            'Not enough sales history to measure accuracy yet.',
        );
        expect(verdict({ ...base, model: null }, 'week')).toBe(
            'Not enough sales history to measure accuracy yet.',
        );
    });
});
