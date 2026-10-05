import { render, screen } from '@testing-library/react';
import type { ReactNode } from 'react';
import { describe, expect, it, vi } from 'vitest';
import Welcome from '@/pages/welcome';

vi.mock('@inertiajs/react', () => ({
    Head: () => null,
    Link: ({
        href,
        children,
        ...rest
    }: {
        href: string | { url: string };
        children: ReactNode;
    }) => (
        <a href={typeof href === 'string' ? href : href.url} {...rest}>
            {children}
        </a>
    ),
}));

describe('Landing page', () => {
    it('says what the app is for, in one line', () => {
        render(<Welcome demo={false} />);

        expect(screen.getByRole('heading', { level: 1 }).textContent).toMatch(
            /what to reorder, how much, and when/i,
        );
    });

    it('leads to the sign-in page', () => {
        render(<Welcome demo={false} />);

        const links = screen.getAllByRole('link', { name: /sign in/i });

        expect(links.length).toBeGreaterThan(0);
        expect(
            links.every((link) => link.getAttribute('href') === '/login'),
        ).toBe(true);
    });

    it('says plainly that it advises and never orders', () => {
        render(<Welcome demo={false} />);

        expect(document.body.textContent).toMatch(/never places? orders/i);
    });

    it('explains how it works and what is under the hood', () => {
        render(<Welcome demo={false} />);

        expect(
            screen.getByRole('heading', { name: /how it works/i }),
        ).toBeTruthy();
        expect(
            screen.getByRole('heading', { name: /under the hood/i }),
        ).toBeTruthy();
        expect(document.body.textContent).toContain('XGBoost');
    });

    it('offers the demo accounts only where they exist', () => {
        const { unmount } = render(<Welcome demo />);

        expect(screen.getByTestId('demo-hint').textContent).toContain(
            'owner@stocksense.test',
        );
        unmount();

        render(<Welcome demo={false} />);

        expect(screen.queryByTestId('demo-hint')).toBeNull();
    });
});
