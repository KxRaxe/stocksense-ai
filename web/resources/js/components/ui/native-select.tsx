import * as React from 'react';

import { cn } from '@/lib/utils';

/**
 * A browser-native <select> styled like <Input>. Unlike the Radix Select it
 * submits normally with a form and works on every device, which suits short
 * lists such as roles, categories and filters.
 */
function NativeSelect({
    className,
    ...props
}: React.ComponentProps<'select'>) {
    return (
        <select
            data-slot="native-select"
            className={cn(
                'flex h-9 w-full rounded-md border-2 border-input bg-card px-3 py-1 text-base shadow-brutal-sm outline-none focus-visible:border-ring focus-visible:ring-[3px] focus-visible:ring-ring/40 disabled:cursor-not-allowed disabled:opacity-50 aria-invalid:border-destructive md:text-sm',
                className,
            )}
            {...props}
        />
    );
}

export { NativeSelect };
