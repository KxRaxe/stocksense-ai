import { render, screen } from '@testing-library/react';
import { describe, expect, it } from 'vitest';
import StockStatusBadge from '@/components/catalog/stock-status-badge';
import type { StockStatus } from '@/types';

describe('StockStatusBadge', () => {
    it.each<[StockStatus, string]>([
        ['out_of_stock', 'Out of stock'],
        ['low', 'Low stock'],
        ['ok', 'In stock'],
    ])('labels %s as "%s"', (status, label) => {
        render(<StockStatusBadge status={status} />);

        expect(screen.getByText(label)).toBeTruthy();
    });
});
