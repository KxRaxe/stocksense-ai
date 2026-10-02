import { describe, expect, it } from 'vitest';
import { formatDateTime, formatMoney, formatNumber } from '@/lib/format';

describe('formatMoney', () => {
    it('writes pesos with grouping and two decimals', () => {
        expect(formatMoney(1250.5)).toBe('₱1,250.50');
        expect(formatMoney(0)).toBe('₱0.00');
        expect(formatMoney(1234567.891)).toBe('₱1,234,567.89');
    });
});

describe('formatNumber', () => {
    it('groups thousands', () => {
        expect(formatNumber(1250)).toBe('1,250');
        expect(formatNumber(-42)).toBe('-42');
    });
});

describe('formatDateTime', () => {
    it('shows a date and a time for an ISO timestamp', () => {
        // The exact hour depends on the machine's time zone, so check the shape.
        const text = formatDateTime('2026-03-05T10:30:00+08:00');

        expect(text).toMatch(/2026/);
        expect(text).toMatch(/\d{1,2}:\d{2}/);
    });
});
