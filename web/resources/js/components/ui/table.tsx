import * as React from 'react';

import { cn } from '@/lib/utils';

function Table({ className, ...props }: React.ComponentProps<'table'>) {
    const container = React.useRef<HTMLDivElement>(null);
    const [scrolls, setScrolls] = React.useState(false);

    React.useLayoutEffect(() => {
        const box = container.current;

        if (!box) {
            return;
        }

        const measure = () => setScrolls(box.scrollWidth > box.clientWidth);
        measure();
        const observer = new ResizeObserver(measure);
        observer.observe(box);

        return () => observer.disconnect();
    }, []);

    return (
        // Only a table wider than its box is a tab stop: a keyboard user needs
        // focus to scroll it sideways, and anything else would be a stray stop.
        <div
            ref={container}
            data-slot="table-container"
            className="relative w-full overflow-x-auto"
            {...(scrolls && {
                tabIndex: 0,
                role: 'region',
                'aria-label':
                    props['aria-label'] ?? 'Table, scroll sideways for more',
            })}
        >
            <table
                data-slot="table"
                className={cn('w-full caption-bottom text-sm', className)}
                {...props}
            />
        </div>
    );
}

function TableHeader({ className, ...props }: React.ComponentProps<'thead'>) {
    return (
        <thead
            data-slot="table-header"
            className={cn('bg-muted [&_tr]:border-b-2', className)}
            {...props}
        />
    );
}

function TableBody({ className, ...props }: React.ComponentProps<'tbody'>) {
    return (
        <tbody
            data-slot="table-body"
            className={cn('[&_tr:last-child]:border-0', className)}
            {...props}
        />
    );
}

function TableRow({ className, ...props }: React.ComponentProps<'tr'>) {
    return (
        <tr
            data-slot="table-row"
            // Dark mode hovers fainter: muted text on accent/30 over the dark
            // card measures 3.5:1; at /15 it is 5.5:1 (WCAG AA needs 4.5:1).
            className={cn(
                'border-b border-border/20 transition-colors hover:bg-accent/30 dark:hover:bg-accent/15',
                className,
            )}
            {...props}
        />
    );
}

function TableHead({ className, ...props }: React.ComponentProps<'th'>) {
    return (
        <th
            data-slot="table-head"
            className={cn(
                'h-10 px-2 text-left align-middle font-mono text-xs font-semibold tracking-wide whitespace-nowrap text-foreground uppercase',
                className,
            )}
            {...props}
        />
    );
}

function TableCell({ className, ...props }: React.ComponentProps<'td'>) {
    return (
        <td
            data-slot="table-cell"
            className={cn('p-2 align-middle whitespace-nowrap', className)}
            {...props}
        />
    );
}

export { Table, TableBody, TableCell, TableHead, TableHeader, TableRow };
