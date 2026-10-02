import { featureLabel } from '@/lib/forecast';
import type { FeatureImportance, ForecastGranularity } from '@/types';

type Props = {
    features: FeatureImportance[];
    granularity: ForecastGranularity;
};

/** What the model leaned on most, as bars scaled to the biggest, in plain words. */
export default function FeatureBars({ features, granularity }: Props) {
    if (features.length === 0) {
        return (
            <p className="text-sm text-muted-foreground">
                The model has not been trained yet.
            </p>
        );
    }

    const largest = Math.max(...features.map((feature) => feature.importance));

    return (
        <ul className="space-y-2" data-test="feature-bars">
            {features.map((feature) => (
                <li
                    key={feature.feature}
                    className="grid grid-cols-[minmax(0,1fr)_minmax(0,1fr)_3.5rem] items-center gap-3 text-sm"
                >
                    <span>{featureLabel(feature.feature, granularity)}</span>
                    <span
                        className="h-2 overflow-hidden rounded-full bg-muted"
                        aria-hidden
                    >
                        <span
                            className="block h-full rounded-full"
                            style={{
                                width: `${(feature.importance / largest) * 100}%`,
                                background: 'var(--chart-2)',
                            }}
                        />
                    </span>
                    <span className="text-right text-muted-foreground">
                        {(feature.importance * 100).toFixed(1)}%
                    </span>
                </li>
            ))}
        </ul>
    );
}
