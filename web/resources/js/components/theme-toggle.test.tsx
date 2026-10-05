import { render, screen } from '@testing-library/react';
import userEvent from '@testing-library/user-event';
import { beforeEach, describe, expect, it } from 'vitest';
import ThemeToggle from '@/components/theme-toggle';
import { initializeTheme } from '@/hooks/use-appearance';

describe('ThemeToggle', () => {
    beforeEach(() => {
        localStorage.clear();
        document.cookie = 'appearance=; max-age=0; path=/';
        document.documentElement.classList.remove('dark');
        // The hook keeps the choice in module state; start each test from "system".
        initializeTheme();
    });

    it('switches a light page to dark, and remembers it', async () => {
        render(<ThemeToggle />);

        await userEvent.click(
            screen.getByRole('button', { name: 'Switch to dark mode' }),
        );

        expect(document.documentElement.classList.contains('dark')).toBe(true);
        expect(localStorage.getItem('appearance')).toBe('dark');
        expect(document.cookie).toContain('appearance=dark');
        expect(
            screen.getByRole('button', { name: 'Switch to light mode' }),
        ).toBeTruthy();
    });

    it('switches back to light', async () => {
        render(<ThemeToggle />);

        await userEvent.click(
            screen.getByRole('button', { name: 'Switch to dark mode' }),
        );
        await userEvent.click(
            screen.getByRole('button', { name: 'Switch to light mode' }),
        );

        expect(document.documentElement.classList.contains('dark')).toBe(false);
        expect(localStorage.getItem('appearance')).toBe('light');
    });
});
