import { render, screen } from '@testing-library/react';
import { afterEach, describe, expect, it, vi } from 'vitest';
import RunProgress from '@/components/forecasts/run-progress';
import type { ActiveRun } from '@/types';

const poll = vi.hoisted(() => ({
    start: vi.fn(),
    stop: vi.fn(),
    reload: vi.fn(),
    options: vi.fn(),
}));

vi.mock('@inertiajs/react', () => ({
    usePoll: (interval: number, requestOptions: unknown) => {
        poll.options(interval, requestOptions);

        return { start: poll.start, stop: poll.stop };
    },
    router: { reload: poll.reload },
}));

const running: ActiveRun = {
    id: 7,
    status: 'running',
    status_label: 'Running',
    started_at: null,
};

const queued: ActiveRun = { ...running, status: 'queued' };

describe('RunProgress', () => {
    afterEach(() => vi.clearAllMocks());

    it('shows nothing and does not poll when no forecast is being made', () => {
        const { container } = render(
            <RunProgress activeRun={null} granularity="week" />,
        );

        expect(container.textContent).toBe('');
        expect(poll.start).not.toHaveBeenCalled();
        expect(poll.reload).not.toHaveBeenCalled();
    });

    it('says a forecast is being made, and keeps checking', () => {
        render(<RunProgress activeRun={running} granularity="week" />);

        expect(screen.getByTestId('run-progress').textContent).toContain(
            'Making the weekly forecast',
        );
        expect(poll.start).toHaveBeenCalledTimes(1);
    });

    it('says when it is still waiting for a worker', () => {
        render(<RunProgress activeRun={queued} granularity="month" />);

        expect(screen.getByTestId('run-progress').textContent).toContain(
            'Waiting to start the forecast',
        );
    });

    it('checks every few seconds, asking the server only for the run', () => {
        render(<RunProgress activeRun={running} granularity="week" />);

        expect(poll.options).toHaveBeenCalledWith(3000, {
            only: ['activeRun'],
        });
    });

    it('tells the person they can leave', () => {
        render(<RunProgress activeRun={running} granularity="week" />);

        expect(screen.getByTestId('run-progress').textContent).toContain(
            'You can leave this page',
        );
    });

    it('reloads the whole page once the forecast is done, so the result appears', () => {
        const { rerender } = render(
            <RunProgress activeRun={running} granularity="week" />,
        );

        expect(poll.reload).not.toHaveBeenCalled();

        rerender(<RunProgress activeRun={null} granularity="week" />);

        expect(poll.reload).toHaveBeenCalledTimes(1);
        expect(poll.stop).toHaveBeenCalled();
        expect(screen.queryByTestId('run-progress')).toBeNull();
    });

    it('does not reload again on later changes', () => {
        const { rerender } = render(
            <RunProgress activeRun={running} granularity="week" />,
        );

        rerender(<RunProgress activeRun={null} granularity="week" />);
        rerender(<RunProgress activeRun={null} granularity="month" />);

        expect(poll.reload).toHaveBeenCalledTimes(1);
    });

    it('carries on, without reloading, as a waiting run starts running', () => {
        const { rerender } = render(
            <RunProgress activeRun={queued} granularity="week" />,
        );

        rerender(<RunProgress activeRun={running} granularity="week" />);

        expect(poll.reload).not.toHaveBeenCalled();
        expect(screen.getByTestId('run-progress').textContent).toContain(
            'Making the weekly forecast',
        );
    });

    it('stops checking when the page is left', () => {
        const { unmount } = render(
            <RunProgress activeRun={running} granularity="week" />,
        );

        unmount();

        expect(poll.stop).toHaveBeenCalled();
    });
});
