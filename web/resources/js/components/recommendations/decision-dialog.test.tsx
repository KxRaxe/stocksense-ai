import { render, screen } from '@testing-library/react';
import type { ReactNode } from 'react';
import { afterEach, describe, expect, it, vi } from 'vitest';
import DecisionDialog from '@/components/recommendations/decision-dialog';
import { makeRecommendation } from '@/test/replenishment';

// Errors the mocked Form hands to the dialog, like a failed server validation.
const formState = vi.hoisted(() => ({ errors: {} as Record<string, string> }));

vi.mock('@inertiajs/react', () => ({
    Form: ({
        children,
        action,
        method,
    }: {
        children: (state: {
            processing: boolean;
            errors: Record<string, string>;
        }) => ReactNode;
        action: string;
        method: string;
    }) => (
        <form action={action} method={method} data-test="decision-form">
            {children({ processing: false, errors: formState.errors })}
        </form>
    ),
}));

const noop = () => {};

const quantity = () => screen.getByLabelText(/^Quantity/) as HTMLInputElement;

describe('DecisionDialog', () => {
    afterEach(() => {
        formState.errors = {};
    });

    it('shows nothing when there is nothing being decided', () => {
        render(
            <DecisionDialog
                kind={null}
                row={makeRecommendation()}
                onClose={noop}
            />,
        );

        expect(screen.queryByRole('dialog')).toBeNull();
    });

    it.each([
        ['accept', 'Accept this recommendation', 'Accept'],
        ['adjust', 'Accept with a different quantity', 'Accept this quantity'],
        ['dismiss', 'Dismiss this recommendation', 'Dismiss'],
        ['cancel', 'Cancel this order', 'Cancel order'],
    ] as const)('says what %s does', (kind, title, button) => {
        render(
            <DecisionDialog
                kind={kind}
                row={makeRecommendation({ final_qty: 130 })}
                onClose={noop}
            />,
        );

        expect(screen.getByText(title)).toBeTruthy();
        expect(screen.getByTestId('confirm-decision-button').textContent).toBe(
            button,
        );
    });

    it('makes clear that nothing is ordered for the person', () => {
        render(
            <DecisionDialog
                kind="accept"
                row={makeRecommendation()}
                onClose={noop}
            />,
        );

        expect(screen.getByRole('dialog').textContent).toContain(
            'Nothing is ordered for you',
        );
    });

    it.each([
        ['accept', '/recommendations/5/accept'],
        ['adjust', '/recommendations/5/adjust'],
        ['dismiss', '/recommendations/5/dismiss'],
        ['cancel', '/recommendations/5/cancel'],
    ] as const)('sends %s to its own address', (kind, address) => {
        render(
            <DecisionDialog
                kind={kind}
                row={makeRecommendation()}
                onClose={noop}
            />,
        );

        const form = screen.getByTestId('decision-form');

        expect(form.getAttribute('action')).toBe(address);
        expect(form.getAttribute('method')).toBe('post');
    });

    it('asks for a quantity only when it can be changed, starting from the recommended one', () => {
        const { unmount } = render(
            <DecisionDialog
                kind="adjust"
                row={makeRecommendation()}
                onClose={noop}
            />,
        );

        expect(quantity().value).toBe('130');
        expect(quantity().getAttribute('name')).toBe('quantity');
        expect(quantity().min).toBe('1');
        expect(screen.getByText(/Recommended: 130 pc\./)).toBeTruthy();

        unmount();
        render(
            <DecisionDialog
                kind="accept"
                row={makeRecommendation()}
                onClose={noop}
            />,
        );

        expect(screen.queryByLabelText(/^Quantity/)).toBeNull();
    });

    it('reminds the person of the pack size and minimum when changing the quantity', () => {
        render(
            <DecisionDialog
                kind="adjust"
                row={makeRecommendation({
                    product: {
                        ...makeRecommendation().product,
                        pack_size: 12,
                        moq: 24,
                    },
                })}
                onClose={noop}
            />,
        );

        expect(screen.getByText(/Packs of 12, at least 24 pc\./)).toBeTruthy();
    });

    it('starts the quantity empty when there was nothing to order', () => {
        render(
            <DecisionDialog
                kind="adjust"
                row={makeRecommendation({ recommended_qty: 0 })}
                onClose={noop}
            />,
        );

        expect(quantity().value).toBe('');
    });

    it('offers an optional note, limited in length', () => {
        render(
            <DecisionDialog
                kind="dismiss"
                row={makeRecommendation()}
                onClose={noop}
            />,
        );

        const note = screen.getByLabelText(
            'Note (optional)',
        ) as HTMLInputElement;

        expect(note.name).toBe('note');
        expect(note.required).toBe(false);
        expect(note.maxLength).toBe(500);
    });

    it.each([
        ['accept', 'e.g. Ordered by phone from the usual supplier'],
        ['dismiss', 'e.g. Discontinuing this product'],
        ['cancel', 'e.g. Supplier could not deliver'],
    ] as const)('suggests a note that fits %s', (kind, example) => {
        render(
            <DecisionDialog
                kind={kind}
                row={makeRecommendation({ final_qty: 130 })}
                onClose={noop}
            />,
        );

        expect(
            screen
                .getByLabelText('Note (optional)')
                .getAttribute('placeholder'),
        ).toBe(example);
    });

    it('shows what the server said was wrong', () => {
        formState.errors = {
            recommendation:
                'This recommendation has already been dealt with or is no longer needed.',
            quantity: 'The quantity field must be at least 1.',
            note: 'The note field must not be greater than 500 characters.',
        };

        render(
            <DecisionDialog
                kind="adjust"
                row={makeRecommendation()}
                onClose={noop}
            />,
        );

        expect(screen.getByTestId('decision-error').textContent).toContain(
            'already been dealt with',
        );
        expect(
            screen.getByText(/quantity field must be at least 1/),
        ).toBeTruthy();
        expect(screen.getByText(/note field must not be greater/)).toBeTruthy();
    });

    it('calls back when it is closed', async () => {
        const onClose = vi.fn();
        const { default: userEvent } =
            await import('@testing-library/user-event');

        render(
            <DecisionDialog
                kind="dismiss"
                row={makeRecommendation()}
                onClose={onClose}
            />,
        );

        await userEvent
            .setup()
            .click(screen.getByRole('button', { name: 'Back' }));

        expect(onClose).toHaveBeenCalled();
    });
});
