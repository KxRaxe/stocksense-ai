import { render, screen, within } from '@testing-library/react';
import userEvent from '@testing-library/user-event';
import type { ReactNode } from 'react';
import { afterEach, describe, expect, it, vi } from 'vitest';
import SalesEntryForm from '@/components/sales/sales-entry-form';
import type { SaleProductOption } from '@/types';

const inertia = vi.hoisted(() => ({
    post: vi.fn(),
    errors: {} as Record<string, string>,
}));

// A working stand-in for Inertia's useForm (state, setData, post, errors) and Link.
vi.mock('@inertiajs/react', async () => {
    const { useState } = await import('react');

    return {
        useForm: <T extends object>(initial: T) => {
            const [data, setDataState] = useState(initial);

            return {
                data,
                setData: (key: keyof T, value: T[keyof T]) =>
                    setDataState((current) => ({ ...current, [key]: value })),
                post: inertia.post,
                errors: inertia.errors,
                processing: false,
            };
        },
        Link: ({ children }: { href: unknown; children: ReactNode }) => (
            <a>{children}</a>
        ),
    };
});

const products: SaleProductOption[] = [
    {
        id: 1,
        sku: 'HW-001',
        name: 'Wire nails',
        unit: 'kg',
        unit_price: 85,
        on_hand: 100,
    },
    {
        id: 2,
        sku: 'HW-002',
        name: 'Cement',
        unit: 'bag',
        unit_price: 275.5,
        on_hand: 4,
    },
];

function setup() {
    const user = userEvent.setup();
    render(<SalesEntryForm products={products} today="2026-10-02" />);

    return user;
}

const line = (position: number) => screen.getByTestId(`sale-line-${position}`);
const product = (position: number) =>
    within(line(position)).getByLabelText(`Product, line ${position + 1}`);
const quantity = (position: number) =>
    within(line(position)).getByLabelText(`Quantity, line ${position + 1}`);
const price = (position: number) =>
    within(line(position)).getByLabelText(`Unit price, line ${position + 1}`);
const saveButton = () =>
    screen.getByTestId('save-sales-button') as HTMLButtonElement;

describe('SalesEntryForm', () => {
    afterEach(() => {
        vi.clearAllMocks();
        inertia.errors = {};
    });

    it('starts with today and one empty line', () => {
        setup();

        expect(
            (screen.getByLabelText('Date of sale') as HTMLInputElement).value,
        ).toBe('2026-10-02');
        expect(screen.getAllByTestId(/^sale-line-/)).toHaveLength(1);
        expect(saveButton().textContent).toContain('Record sale');
    });

    it('cannot be saved until every line has a product and a quantity', async () => {
        const user = setup();

        expect(saveButton().disabled).toBe(true);

        await user.selectOptions(product(0), '1');
        expect(saveButton().disabled).toBe(true);

        await user.type(quantity(0), '3');
        expect(saveButton().disabled).toBe(false);
    });

    it('fills in the usual price when a product is chosen, and lets it be changed', async () => {
        const user = setup();

        await user.selectOptions(product(0), '2');
        expect((price(0) as HTMLInputElement).value).toBe('275.50');

        await user.clear(price(0));
        await user.type(price(0), '260');
        expect((price(0) as HTMLInputElement).value).toBe('260');
    });

    it('shows how much is on hand, and warns when more is sold than that', async () => {
        const user = setup();

        await user.selectOptions(product(0), '2');
        expect(within(line(0)).getByText('4 bag on hand')).toBeTruthy();

        await user.type(quantity(0), '5');
        expect(
            within(line(0)).getByText(
                'Only 4 bag on hand. Stock will go negative.',
            ),
        ).toBeTruthy();
    });

    it('does not warn when the quantity is within what is on hand', async () => {
        const user = setup();

        await user.selectOptions(product(0), '2');
        await user.type(quantity(0), '4');

        expect(
            within(line(0)).queryByText(/Stock will go negative/),
        ).toBeNull();
    });

    it('adds and removes lines, and keeps at least one', async () => {
        const user = setup();

        const remove = (position: number) =>
            within(line(position)).getByLabelText(
                `Remove line ${position + 1}`,
            ) as HTMLButtonElement;

        expect(remove(0).disabled).toBe(true);

        await user.click(screen.getByTestId('add-line-button'));
        expect(screen.getAllByTestId(/^sale-line-/)).toHaveLength(2);
        expect(remove(0).disabled).toBe(false);

        await user.click(remove(0));
        expect(screen.getAllByTestId(/^sale-line-/)).toHaveLength(1);
    });

    it('keeps the other lines when one is removed', async () => {
        const user = setup();

        await user.selectOptions(product(0), '1');
        await user.click(screen.getByTestId('add-line-button'));
        await user.selectOptions(product(1), '2');

        await user.click(within(line(0)).getByLabelText('Remove line 1'));

        expect((product(0) as HTMLSelectElement).value).toBe('2');
    });

    it('totals the lines', async () => {
        const user = setup();

        await user.selectOptions(product(0), '1');
        await user.type(quantity(0), '2'); // 2 x 85 = 170
        await user.click(screen.getByTestId('add-line-button'));
        await user.selectOptions(product(1), '2');
        await user.type(quantity(1), '1'); // 1 x 275.50

        expect(within(line(0)).getByText('₱170.00')).toBeTruthy();
        expect(within(line(1)).getByText('₱275.50')).toBeTruthy();
        expect(screen.getByText('₱445.50')).toBeTruthy();
        expect(saveButton().textContent).toContain('Record 2 sales');
    });

    it('sends the date and every line when saved', async () => {
        const user = setup();

        await user.selectOptions(product(0), '1');
        await user.type(quantity(0), '12');
        await user.click(saveButton());

        expect(inertia.post).toHaveBeenCalledTimes(1);
        expect(inertia.post).toHaveBeenCalledWith('/sales');
    });

    it('shows a server error under the line it belongs to', () => {
        inertia.errors = {
            'items.0.quantity': 'The quantity must be at least 1.',
            sold_on: 'The date cannot be in the future.',
        };

        setup();

        expect(
            within(line(0)).getByText('The quantity must be at least 1.'),
        ).toBeTruthy();
        expect(
            screen.getByText('The date cannot be in the future.'),
        ).toBeTruthy();
    });
});
