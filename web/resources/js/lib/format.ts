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
