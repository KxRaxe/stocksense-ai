import { router, usePoll } from '@inertiajs/react';
import { useEffect, useRef } from 'react';
import { Spinner } from '@/components/ui/spinner';
import { periodNoun } from '@/lib/forecast';
import type { ActiveRun, ForecastGranularity } from '@/types';

type Props = {
    activeRun: ActiveRun | null;
    granularity: ForecastGranularity;
};

/**
 * Shows that a forecast is being made, and keeps the page up to date while it
 * is. Checks every few seconds; when the run finishes, reloads the page so the
 * new forecast (or the reason it failed) appears without anyone pressing anything.
 */
export default function RunProgress({ activeRun, granularity }: Props) {
    const wasActive = useRef(false);
    const { start, stop } = usePoll(
        3000,
        { only: ['activeRun'] },
        { autoStart: false },
    );

    useEffect(() => {
        if (activeRun !== null) {
            wasActive.current = true;
            start();

            return stop;
        }

        stop();

        if (wasActive.current) {
            wasActive.current = false;
            router.reload();
        }

        return stop;
        // start and stop come from the poll hook; only the run itself matters.
        // eslint-disable-next-line react-hooks/exhaustive-deps
    }, [activeRun?.id, activeRun?.status]);

    if (activeRun === null) {
        return null;
    }

    return (
        <div
            className="flex items-start gap-3 rounded-lg border p-4 text-sm"
            role="status"
            data-test="run-progress"
        >
            <Spinner className="mt-0.5" />
            <div>
                <p className="font-medium">
                    {activeRun.status === 'queued'
                        ? 'Waiting to start the forecast...'
                        : `Making the ${granularity}ly forecast...`}
                </p>
                <p className="text-muted-foreground">
                    The model is learning from your sales and measuring how well
                    it would have done on recent {periodNoun(granularity)}. This
                    usually takes a minute or two. You can leave this page; it
                    updates itself when the forecast is ready.
                </p>
            </div>
        </div>
    );
}
