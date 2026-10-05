import LinkTabs from '@/components/link-tabs';
import type { ForecastGranularity, GranularityOption } from '@/types';

type Props = {
    granularity: ForecastGranularity;
    options: GranularityOption[];
    /** The address of the same page at another granularity. */
    href: (granularity: ForecastGranularity) => string;
};

/** Weekly / Monthly switch. */
export default function GranularityTabs({ granularity, options, href }: Props) {
    return (
        <LinkTabs
            label="Forecast period"
            testPrefix="granularity"
            current={granularity}
            tabs={options.map((option) => ({
                key: option.value,
                label: option.label,
                href: href(option.value),
            }))}
        />
    );
}
