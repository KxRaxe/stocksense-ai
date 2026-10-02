import { usePage } from '@inertiajs/react';
import { renderHook } from '@testing-library/react';
import { afterEach, describe, expect, it, vi } from 'vitest';
import { useCan } from '@/hooks/use-can';

vi.mock('@inertiajs/react', () => ({ usePage: vi.fn() }));

/** Pretend the server shared these permissions with the page. */
function signedInWith(permissions: string[]) {
    vi.mocked(usePage).mockReturnValue({
        props: { auth: { permissions } },
    } as unknown as ReturnType<typeof usePage>);
}

describe('useCan', () => {
    afterEach(() => {
        vi.resetAllMocks();
    });

    it('is true only for permissions the user was given', () => {
        signedInWith(['catalog.view', 'inventory.adjust']);

        const { result } = renderHook(() => useCan());

        expect(result.current('catalog.view')).toBe(true);
        expect(result.current('inventory.adjust')).toBe(true);
        expect(result.current('users.manage')).toBe(false);
        expect(result.current('catalog.manage')).toBe(false);
    });

    it('is false for everything when there are no permissions', () => {
        signedInWith([]);

        const { result } = renderHook(() => useCan());

        expect(result.current('catalog.view')).toBe(false);
    });
});
