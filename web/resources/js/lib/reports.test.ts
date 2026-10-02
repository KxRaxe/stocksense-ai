import { describe, expect, it } from 'vitest';
import { formatMoneyCompact } from '@/lib/format';
import { formatCell, isNumeric } from '@/lib/reports';

describe('formatCell', () => {
    it('writes each kind of value for the screen', () => {
        expect(formatCell(1500.5, 'money')).toBe('₱1,500.50');
        expect(formatCell(1250, 'integer')).toBe('1,250');
        expect(formatCell(12.46, 'decimal')).toBe('12.5');
        expect(formatCell(65.22, 'percent')).toBe('65.2%');
        expect(formatCell('2026-10-05', 'date')).toBe('Oct 5, 2026');
        expect(formatCell('Rice', 'text')).toBe('Rice');
    });

    it('writes a moment with its time', () => {
        expect(formatCell('2026-10-05T08:30:00+08:00', 'datetime')).toMatch(
            /2026.*\d{1,2}:\d{2}/,
        );
    });

    it('writes a gap as a dash, whatever kind it is', () => {
        for (const type of [
            'text',
            'money',
            'integer',
            'decimal',
            'percent',
            'date',
            'datetime',
        ] as const) {
            expect(formatCell(null, type)).toBe('-');
            expect(formatCell('', type)).toBe('-');
        }
    });

    it('keeps a real zero', () => {
        expect(formatCell(0, 'integer')).toBe('0');
        expect(formatCell(0, 'money')).toBe('₱0.00');
        expect(formatCell(0, 'percent')).toBe('0.0%');
    });

    it('takes a number that arrived as text', () => {
        expect(formatCell('1500', 'money')).toBe('₱1,500.00');
    });
});

describe('isNumeric', () => {
    it('lines numbers up on the right and leaves the rest', () => {
        expect(
            ['integer', 'decimal', 'money', 'percent'].every((type) =>
                isNumeric(type as never),
            ),
        ).toBe(true);
        expect(
            ['text', 'date', 'datetime'].some((type) =>
                isNumeric(type as never),
            ),
        ).toBe(false);
    });
});

describe('formatMoneyCompact', () => {
    it('shortens large amounts for chart axes', () => {
        expect(formatMoneyCompact(12500)).toBe('₱12.5K');
        expect(formatMoneyCompact(1_200_000)).toBe('₱1.2M');
        expect(formatMoneyCompact(950)).toBe('₱950');
        expect(formatMoneyCompact(0)).toBe('₱0');
    });
});
