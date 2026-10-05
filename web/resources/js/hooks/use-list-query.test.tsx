import { act, renderHook } from '@testing-library/react';
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';
import { useDebouncedSearch } from '@/hooks/use-list-query';

vi.mock('@inertiajs/react', () => ({ router: { get: vi.fn() } }));

describe('useDebouncedSearch', () => {
    beforeEach(() => vi.useFakeTimers());
    afterEach(() => vi.useRealTimers());

    it('applies the search once typing pauses, with the last value', () => {
        const apply = vi.fn();
        const { result } = renderHook(() => useDebouncedSearch('', apply));

        act(() => result.current[1]('ce'));
        act(() => {
            vi.advanceTimersByTime(200);
        });
        act(() => result.current[1]('cement'));
        act(() => {
            vi.advanceTimersByTime(299);
        });

        expect(apply).not.toHaveBeenCalled();

        act(() => {
            vi.advanceTimersByTime(1);
        });

        expect(apply).toHaveBeenCalledTimes(1);
        expect(apply).toHaveBeenCalledWith({ search: 'cement' });
        expect(result.current[0]).toBe('cement');
    });

    it('does nothing while the search matches the current filter', () => {
        const apply = vi.fn();
        renderHook(() => useDebouncedSearch('nails', apply));

        act(() => {
            vi.advanceTimersByTime(1000);
        });

        expect(apply).not.toHaveBeenCalled();
    });
});
