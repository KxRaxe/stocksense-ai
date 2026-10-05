import { usePage } from '@inertiajs/react';

import AppLogoIcon from '@/components/app-logo-icon';

export default function AppLogo() {
    const { name } = usePage().props;

    return (
        <>
            <div className="relative flex aspect-square size-8 shrink-0 items-center justify-center rounded-md border-2 border-ink bg-sidebar-primary text-sidebar-primary-foreground shadow-brutal-sm dark:border-transparent">
                <AppLogoIcon className="size-5 fill-current" />
                <span
                    aria-hidden="true"
                    className="absolute -top-2 -right-2.5 rotate-12 rounded-sm border-2 border-ink bg-accent px-0.5 font-mono text-[8px] leading-tight font-bold text-ink group-data-[collapsible=icon]:hidden"
                >
                    AI
                </span>
            </div>
            <div className="ml-1 grid flex-1 text-left text-sm">
                <span className="mb-0.5 truncate leading-tight font-bold tracking-tight">
                    {name}
                </span>
            </div>
        </>
    );
}
