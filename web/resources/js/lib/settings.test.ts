import { describe, expect, it } from 'vitest';
import { describeValue, stepFor } from '@/lib/settings';
import type { SettingField } from '@/types';

const field = (changes: Partial<SettingField>): SettingField => ({
    key: 'review_days',
    label: 'Review period',
    help: 'How often.',
    type: 'integer',
    unit: 'days',
    min: 1,
    max: 60,
    choices: [],
    value: 7,
    default: 7,
    is_default: true,
    ...changes,
});

describe('describeValue', () => {
    it('adds the unit to a number', () => {
        expect(describeValue(field({}))).toBe('7 days');
        expect(describeValue(field({ unit: '%', value: 95 }))).toBe('95 %');
    });

    it('leaves a number with no unit as it is', () => {
        expect(describeValue(field({ unit: null, value: 3 }))).toBe('3');
    });

    it('writes a switch as On or Off', () => {
        const toggle = field({ type: 'boolean', unit: null, value: true });

        expect(describeValue(toggle)).toBe('On');
        expect(describeValue(toggle, false)).toBe('Off');
    });

    it('writes a choice as its label, whether the value arrives as a number or text', () => {
        const day = field({
            type: 'choice',
            unit: null,
            choices: [
                { value: 1, label: 'Monday' },
                { value: 0, label: 'Sunday' },
            ],
            value: 1,
        });

        expect(describeValue(day)).toBe('Monday');
        expect(describeValue(day, 0)).toBe('Sunday');
        expect(describeValue(day, '0')).toBe('Sunday');
    });

    it('falls back to the value for a choice it does not know', () => {
        expect(
            describeValue(field({ type: 'choice', choices: [], value: 9 })),
        ).toBe('9');
    });

    it('writes a time as it is', () => {
        expect(
            describeValue(field({ type: 'time', unit: null, value: '06:00' })),
        ).toBe('06:00');
    });

    it('describes another value than the current one when asked', () => {
        expect(describeValue(field({ value: 14 }), 7)).toBe('7 days');
    });
});

describe('stepFor', () => {
    it('moves whole numbers by one and the rest by a tenth', () => {
        expect(stepFor(field({ type: 'integer' }))).toBe('1');
        expect(stepFor(field({ type: 'number' }))).toBe('0.1');
    });
});
