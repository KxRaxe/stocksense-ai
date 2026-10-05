import { render, screen } from '@testing-library/react';
import type { ReactNode } from 'react';
import { describe, expect, it, vi } from 'vitest';
import RecommendationSummary from '@/components/recommendations/recommendation-summary';
import { noFilters } from '@/test/replenishment';
import type { RecommendationCounts } from '@/types';

vi.mock('@inertiajs/react', () => ({
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

const counts: RecommendationCounts = {
    critical: 3,
    low: 12,
    watch: 1204,
    overstock: 0,
    ordered: 7,
};

const card = (key: string) => screen.getByTestId(`count-${key}`);

describe('RecommendationSummary', () => {
    it('shows how many products are at each level', () => {
        render(<RecommendationSummary counts={counts} filters={noFilters} />);

        expect(card('critical').textContent).toContain('3');
        expect(card('critical').textContent).toContain('Critical');
        expect(card('low').textContent).toContain('12');
        expect(card('watch').textContent).toContain('1,204');
        expect(card('overstock').textContent).toContain('0');
        expect(card('ordered').textContent).toContain('7');
        expect(card('ordered').textContent).toContain('On order');
    });

    it('is also the way in: each card narrows the list to it', () => {
        render(<RecommendationSummary counts={counts} filters={noFilters} />);

        expect(card('critical').getAttribute('href')).toBe(
            '/recommendations?risk=critical',
        );
        expect(card('low').getAttribute('href')).toBe(
            '/recommendations?risk=low',
        );
        expect(card('watch').getAttribute('href')).toBe(
            '/recommendations?risk=watch',
        );
        expect(card('overstock').getAttribute('href')).toBe(
            '/recommendations?view=overstock',
        );
        expect(card('ordered').getAttribute('href')).toBe(
            '/recommendations?view=decided',
        );
    });

    it('marks the level the list is narrowed to', () => {
        render(
            <RecommendationSummary
                counts={counts}
                filters={{ ...noFilters, risk: 'low' }}
            />,
        );

        expect(card('low').getAttribute('aria-current')).toBe('true');
        expect(card('critical').getAttribute('aria-current')).toBeNull();
    });

    it('marks overstock and on order when those are being shown', () => {
        const { unmount } = render(
            <RecommendationSummary
                counts={counts}
                filters={{ ...noFilters, view: 'overstock' }}
            />,
        );

        expect(card('overstock').getAttribute('aria-current')).toBe('true');
        expect(card('low').getAttribute('aria-current')).toBeNull();

        unmount();
        render(
            <RecommendationSummary
                counts={counts}
                filters={{ ...noFilters, view: 'decided' }}
            />,
        );

        expect(card('ordered').getAttribute('aria-current')).toBe('true');
    });

    it('does not mark a risk when it is the decided list being shown', () => {
        render(
            <RecommendationSummary
                counts={counts}
                filters={{ ...noFilters, view: 'decided', risk: 'critical' }}
            />,
        );

        expect(card('critical').getAttribute('aria-current')).toBeNull();
    });
});
