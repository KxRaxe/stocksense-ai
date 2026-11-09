import { act, render, screen } from '@testing-library/react';
import { afterEach, describe, expect, it, vi } from 'vitest';
import { Table, TableBody, TableCell, TableRow } from '@/components/ui/table';

/** jsdom measures everything as zero, so each test says how wide things are. */
function widths(scroll: number, client: number) {
    vi.spyOn(HTMLElement.prototype, 'scrollWidth', 'get').mockReturnValue(scroll);
    vi.spyOn(HTMLElement.prototype, 'clientWidth', 'get').mockReturnValue(client);
}

function container() {
    render(
        <Table aria-label="Stock levels">
            <TableBody>
                <TableRow>
                    <TableCell>Rice</TableCell>
                </TableRow>
            </TableBody>
        </Table>,
    );

    return screen.getByRole('table').closest<HTMLElement>('[data-slot="table-container"]')!;
}

describe('Table', () => {
    afterEach(() => {
        vi.restoreAllMocks();
        vi.unstubAllGlobals();
    });

    it('is not a tab stop when it fits', () => {
        widths(300, 300);
        const box = container();

        expect(box.hasAttribute('tabindex')).toBe(false);
        expect(box.getAttribute('role')).toBeNull();
    });

    it('is a named, focusable region when it is wider than its container', () => {
        widths(900, 300);
        const box = container();

        expect(box.tabIndex).toBe(0);
        expect(screen.getByRole('region', { name: 'Stock levels' })).toBe(box);
    });

    it('becomes a tab stop when its rows grow wider than the box', () => {
        // The box keeps its size; only the table inside grows (new rows after a filter).
        const watched: Element[] = [];
        let remeasure = () => {};
        vi.stubGlobal(
            'ResizeObserver',
            class {
                constructor(callback: () => void) {
                    remeasure = callback;
                }
                observe(target: Element) {
                    watched.push(target);
                }
                disconnect() {}
            },
        );
        widths(300, 300);
        const box = container();
        expect(box.hasAttribute('tabindex')).toBe(false);

        expect(watched).toContain(screen.getByRole('table'));
        widths(900, 300);
        act(() => remeasure());

        expect(box.tabIndex).toBe(0);
    });

    it('still has a name when none is given', () => {
        widths(900, 300);
        render(
            <Table>
                <TableBody>
                    <TableRow>
                        <TableCell>Rice</TableCell>
                    </TableRow>
                </TableBody>
            </Table>,
        );

        expect(screen.getByRole('region').getAttribute('aria-label')).toBeTruthy();
    });
});
