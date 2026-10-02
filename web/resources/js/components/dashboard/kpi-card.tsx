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

const tones = {
    default: '',
    good: 'text-emerald-600 dark:text-emerald-400',
    warning: 'text-amber-600 dark:text-amber-400',
    bad: 'text-red-600 dark:text-red-400',
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
            <p className="text-sm text-muted-foreground">{label}</p>
            <p
                className={cn('mt-1 text-2xl font-semibold', tones[tone])}
                data-test="kpi-value"
            >
                {value}
            </p>
            {children && (
                <div className="mt-1 space-y-0.5 text-sm text-muted-foreground">
                    {children}
                </div>
            )}
        </>
    );

    const className = 'block rounded-lg border p-4';

    return href ? (
        <Link
            href={href}
            className={cn(className, 'transition-colors hover:bg-muted/50')}
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
