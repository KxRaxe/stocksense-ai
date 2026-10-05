import { Link } from '@inertiajs/react';

type Tab = { key: string; label: string; href: string };

type Props = {
    /** What the tabs choose between, for screen readers. */
    label: string;
    tabs: Tab[];
    current: string;
    /** `data-test` of each tab is `${testPrefix}-${key}`. */
    testPrefix: string;
};

/** A row of tabs where each tab is a link, so the choice lives in the URL. */
export default function LinkTabs({ label, tabs, current, testPrefix }: Props) {
    return (
        <nav
            className="inline-flex flex-wrap rounded-lg bg-muted p-1 text-sm"
            role="tablist"
            aria-label={label}
        >
            {tabs.map((tab) => {
                const selected = tab.key === current;

                return (
                    <Link
                        key={tab.key}
                        href={tab.href}
                        role="tab"
                        aria-selected={selected}
                        preserveScroll
                        className={`rounded-md px-3 py-1 font-medium transition-colors ${
                            selected
                                ? 'bg-background shadow-sm'
                                : 'text-foreground/70 hover:text-foreground'
                        }`}
                        data-test={`${testPrefix}-${tab.key}`}
                    >
                        {tab.label}
                    </Link>
                );
            })}
        </nav>
    );
}
