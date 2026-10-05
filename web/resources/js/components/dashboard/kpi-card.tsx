import { Link } from '@inertiajs/react';
import type { InertiaLinkProps } from '@inertiajs/react';
import type { ReactNode } from 'react';
import { cn } from '@/lib/utils';

type Props = {
    label: string;
    /** The headline figure. */
    value: ReactNode;
    /** One or two lines under it. */
    children?: ReactNode;
    href?: InertiaLinkProps['href'];
    /** Colours the figure to draw the eye. */
    tone?: 'default' | 'good' | 'warning' | 'bad';
    testId: string;
};

// A toned figure sits on a fill, read in ink: the same in light and dark.
const chip =
    'rounded-md border-2 border-ink px-1.5 text-ink dark:border-transparent';

const tones = {
    default: '',
    good: `${chip} bg-ok`,
    warning: `${chip} bg-low`,
    bad: `${chip} bg-critical`,
};

/** One headline figure with a little context. A link when there is somewhere to look closer. */
export default function KpiCard({
    label,
    value,
    children,
    href,
    tone = 'default',
    testId,
}: Props) {
    const content = (
        <>
            <p className="font-mono text-xs font-semibold tracking-wide text-muted-foreground uppercase">
                {label}
            </p>
            <p className="mt-2">
                <span
                    className={cn(
                        'inline-block font-mono text-2xl font-semibold tabular-nums',
                        tones[tone],
                    )}
                    data-test="kpi-value"
                    data-tone={tone}
                >
                    {value}
                </span>
            </p>
            {children && (
                <div className="mt-1 space-y-0.5 text-sm text-muted-foreground">
                    {children}
                </div>
            )}
        </>
    );

    const className = 'block rounded-xl border-2 bg-card p-4 shadow-brutal';

    return href ? (
        <Link
            href={href}
            className={cn(className, 'press hover:bg-accent/20')}
            data-test={testId}
        >
            {content}
        </Link>
    ) : (
        <div className={className} data-test={testId}>
            {content}
        </div>
    );
}
