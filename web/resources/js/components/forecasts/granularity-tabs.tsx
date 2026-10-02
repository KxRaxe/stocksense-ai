import { Link } from '@inertiajs/react';
import type { ForecastGranularity, GranularityOption } from '@/types';

type Props = {
    granularity: ForecastGranularity;
    options: GranularityOption[];
    /** The address of the same page at another granularity. */
    href: (granularity: ForecastGranularity) => string;
};

/** Weekly / Monthly switch. Each option is a link, so the choice is in the URL. */
export default function GranularityTabs({ granularity, options, href }: Props) {
    return (
        <div
            className="inline-flex rounded-lg bg-muted p-1 text-sm"
            role="tablist"
            aria-label="Forecast period"
        >
            {options.map((option) => {
                const selected = option.value === granularity;

                return (
                    <Link
                        key={option.value}
                        href={href(option.value)}
                        role="tab"
                        aria-selected={selected}
                        preserveScroll
                        className={`rounded-md px-3 py-1 font-medium transition-colors ${
                            selected
                                ? 'bg-background shadow-sm'
                                : 'text-muted-foreground hover:text-foreground'
                        }`}
                        data-test={`granularity-${option.value}`}
                    >
                        {option.label}
                    </Link>
                );
            })}
        </div>
    );
}
