import { formatDate } from '@/lib/format';
import type { ForecastGranularity, Headline } from '@/types';

const months = [
    'Jan',
    'Feb',
    'Mar',
    'Apr',
    'May',
    'Jun',
    'Jul',
    'Aug',
    'Sep',
    'Oct',
    'Nov',
    'Dec',
];

/**
 * A period's start as a short label: "Sep 28" for a week, "Oct 2026" for a
 * month. Read from the text, not through a Date, so it never slips a day.
 */
export function periodLabel(
    start: string,
    granularity: ForecastGranularity,
): string {
    const [year, month, day] = start.split('-').map(Number);

    return granularity === 'month'
        ? `${months[month - 1]} ${year}`
        : `${months[month - 1]} ${day}`;
}

/** "week" -> "weeks", "month" -> "months", or the singular for one. */
export function periodNoun(
    granularity: ForecastGranularity,
    count = 2,
): string {
    return count === 1 ? granularity : `${granularity}s`;
}

/** A percentage with one decimal, or a dash when there is none. */
export function formatPercent(value: number | null): string {
    return value === null ? '-' : `${value.toFixed(1)}%`;
}

/** Units with up to one decimal: 12 -> "12", 12.46 -> "12.5". */
export function formatUnits(value: number): string {
    return new Intl.NumberFormat('en-PH', { maximumFractionDigits: 1 }).format(
        value,
    );
}

/** "+12.0%" or "-5.5%". */
export function formatChange(change: number): string {
    return `${change > 0 ? '+' : ''}${change.toFixed(1)}%`;
}

/** The forecast's range for display: "12 (8-17)". */
export function formatRange(
    median: number,
    lower: number,
    upper: number,
): string {
    return `${formatUnits(median)} (${formatUnits(lower)}-${formatUnits(upper)})`;
}

/** What the model was given to learn from, in words a shop owner would use. */
const featureLabels: Record<string, string> = {
    zero_share: 'How often recent periods had no sales',
    age: 'How long the product has been sold',
    category_code: 'Product category',
    log_scale: 'How much the product usually sells',
    log_price: 'Selling price',
    period_of_year: 'Time of year',
    month: 'Month',
    quarter: 'Quarter',
    december_share: 'December',
    christmas_rush: 'Christmas rush (15-24 December)',
    back_to_school: 'Back-to-school season (mid May to June)',
    paydays: 'Paydays (the 15th and the last day of the month)',
    days_in_period: 'Days in the period',
};

/** A model feature's name as a plain-language label. */
export function featureLabel(
    feature: string,
    granularity: ForecastGranularity,
): string {
    if (featureLabels[feature]) {
        return featureLabels[feature];
    }

    const unit = periodNoun(granularity, 2);
    const [, kind, count] =
        /^(lag|roll_mean|roll_std)_(\d+)$/.exec(feature) ?? [];

    if (kind === 'lag') {
        const n = Number(count);
        const season = granularity === 'week' ? 52 : 12;

        return n === season
            ? `Sales at this time last year`
            : n === 1
              ? `Sales in the ${periodNoun(granularity, 1)} before`
              : `Sales ${n} ${unit} ago`;
    }

    if (kind === 'roll_mean') {
        return `Average of the last ${count} ${unit}`;
    }

    if (kind === 'roll_std') {
        return `How much sales varied over the last ${count} ${unit}`;
    }

    return feature;
}

/**
 * One sentence on how the model compares with the simple yardsticks, for the
 * top of the Accuracy page. In plain words: the error is "off by" a percentage
 * of what sold.
 */
export function verdict(
    headline: Headline | null,
    granularity: ForecastGranularity,
): string {
    if (headline === null || headline.model === null) {
        return 'Not enough sales history to measure accuracy yet.';
    }

    const model = headline.model;
    const rivals = [
        {
            name: `the same ${periodNoun(granularity, 1)} last year`,
            error: headline.seasonal_naive,
        },
        { name: 'a recent average', error: headline.moving_average },
    ].filter(
        (rival): rival is { name: string; error: number } =>
            rival.error !== null,
    );

    const beaten = rivals.filter((rival) => model < rival.error);
    const lead = `On recent ${periodNoun(granularity)}, forecasts were off by ${model.toFixed(1)}% of units sold`;

    if (rivals.length > 0 && beaten.length === rivals.length) {
        return `${lead}, closer than ${rivals.map((rival) => rival.name).join(' and ')}.`;
    }

    if (beaten.length === 0) {
        return `${lead}, no better than simpler guesses. Treat the forecasts with care.`;
    }

    const missed = rivals.find((rival) => !beaten.includes(rival));

    return `${lead}, closer than ${beaten[0].name} but not ${missed?.name}.`;
}

/** The date a forecast run covers up to, for "as of" labels. */
export function asOfLabel(asOf: string | null): string {
    return asOf === null ? '-' : formatDate(asOf);
}
