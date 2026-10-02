import { formatUnits } from '@/lib/forecast';
import {
    formatDate,
    formatDateTime,
    formatMoney,
    formatNumber,
} from '@/lib/format';
import type { ColumnType, ReportCell } from '@/types';

/** A report value written for the screen, by what kind of value it is. A gap is a dash. */
export function formatCell(value: ReportCell, type: ColumnType): string {
    if (value === null || value === '') {
        return '-';
    }

    switch (type) {
        case 'money':
            return formatMoney(Number(value));
        case 'integer':
            return formatNumber(Number(value));
        case 'decimal':
            return formatUnits(Number(value));
        case 'percent':
            return `${Number(value).toFixed(1)}%`;
        case 'date':
            return formatDate(String(value));
        case 'datetime':
            return formatDateTime(String(value));
        default:
            return String(value);
    }
}

/** Numbers line up on the right. */
export function isNumeric(type: ColumnType): boolean {
    return ['integer', 'decimal', 'money', 'percent'].includes(type);
}
