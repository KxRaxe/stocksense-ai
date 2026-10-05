const peso = new Intl.NumberFormat('en-PH', {
    style: 'currency',
    currency: 'PHP',
});

const integer = new Intl.NumberFormat('en-PH');

const dateTime = new Intl.DateTimeFormat('en-PH', {
    dateStyle: 'medium',
    timeStyle: 'short',
});

/** 1250.5 -> "₱1,250.50" */
export function formatMoney(amount: number): string {
    return peso.format(amount);
}

/** 1250 -> "1,250" */
export function formatNumber(value: number): string {
    return integer.format(value);
}

/** An ISO 8601 timestamp as local date and time. */
export function formatDateTime(iso: string): string {
    return dateTime.format(new Date(iso));
}

const day = new Intl.DateTimeFormat('en-PH', { dateStyle: 'medium' });

/**
 * A calendar date such as "2026-03-05" as "Mar 5, 2026". Built from its parts
 * in local time, so it never slips a day across time zones.
 */
export function formatDate(date: string): string {
    const [year, month, dayOfMonth] = date.split('-').map(Number);

    return day.format(new Date(year, month - 1, dayOfMonth));
}

const compactPeso = new Intl.NumberFormat('en-PH', {
    style: 'currency',
    currency: 'PHP',
    notation: 'compact',
    minimumFractionDigits: 0,
    maximumFractionDigits: 1,
});

/** 12500 -> "₱12.5K", for chart axes where the full figure will not fit. */
export function formatMoneyCompact(amount: number): string {
    return compactPeso.format(amount);
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
