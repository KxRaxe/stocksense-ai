import { Link } from '@inertiajs/react';
import type { ComponentProps } from 'react';
import { cn } from '@/lib/utils';

type Props = ComponentProps<typeof Link>;

export default function TextLink({
    className = '',
    children,
    ...props
}: Props) {
    return (
        <Link
            className={cn(
                'text-foreground underline decoration-primary decoration-2 underline-offset-4 transition-colors duration-300 ease-out hover:decoration-accent',
                className,
            )}
            {...props}
        >
            {children}
        </Link>
    );
}
