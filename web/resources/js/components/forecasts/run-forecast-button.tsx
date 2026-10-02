import { router } from '@inertiajs/react';
import { RefreshCw } from 'lucide-react';
import { useState } from 'react';
import { toast } from 'sonner';
import { Button } from '@/components/ui/button';
import { Spinner } from '@/components/ui/spinner';
import { firstError } from '@/lib/errors';
import { run } from '@/routes/forecasts';
import type { ForecastGranularity } from '@/types';

type Props = {
    granularity: ForecastGranularity;
    /** True while a run is already going, so there is nothing to start. */
    disabled?: boolean;
};

/** Starts a new forecast. The server checks the person is allowed to. */
export default function RunForecastButton({
    granularity,
    disabled = false,
}: Props) {
    const [starting, setStarting] = useState(false);

    return (
        <Button
            onClick={() =>
                router.post(
                    run.url(),
                    { granularity },
                    {
                        preserveScroll: true,
                        onStart: () => setStarting(true),
                        onFinish: () => setStarting(false),
                        onError: (errors) => toast.error(firstError(errors)),
                    },
                )
            }
            disabled={disabled || starting}
            data-test="run-forecast-button"
        >
            {starting ? <Spinner /> : <RefreshCw />}
            Run {granularity}ly forecast
        </Button>
    );
}
