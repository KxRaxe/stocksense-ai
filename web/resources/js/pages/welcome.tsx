import { Head, Link } from '@inertiajs/react';
import {
    ArrowRight,
    Bell,
    BrainCircuit,
    ClipboardCheck,
    FileSpreadsheet,
    LayoutDashboard,
    PackageX,
    ScrollText,
    ShieldCheck,
    Shuffle,
    TrendingUp,
    Upload,
    Users,
    Warehouse,
} from 'lucide-react';
import type { LucideIcon } from 'lucide-react';
import type { ReactNode } from 'react';
import AppLogoIcon from '@/components/app-logo-icon';
import ThemeToggle from '@/components/theme-toggle';
import { Button } from '@/components/ui/button';
import { login } from '@/routes';

type Props = {
    /** The demo accounts exist (anywhere but production). */
    demo: boolean;
};

const problems: {
    icon: LucideIcon;
    title: string;
    text: string;
    fill: string;
}[] = [
    {
        icon: PackageX,
        title: 'Empty shelves',
        text: 'A best-seller runs out on payday weekend, and the customer buys it next door.',
        fill: 'bg-critical',
    },
    {
        icon: Warehouse,
        title: 'Money on the shelf',
        text: 'Slow sellers pile up in the back while cash is needed for the fast ones.',
        fill: 'bg-overstock',
    },
    {
        icon: Shuffle,
        title: 'Ordering by feel',
        text: 'Reordering from memory works until the season changes or someone is away.',
        fill: 'bg-low',
    },
];

const steps: { title: string; text: string }[] = [
    {
        title: 'Bring in your sales',
        text: 'Type them in, or import a CSV or Excel file. A preview shows what will go in and what is wrong before anything is saved.',
    },
    {
        title: 'Forecast what will sell',
        text: 'A machine-learning model forecasts every product week by week or month by month, with a likely range, and is checked against simple guesses it must beat.',
    },
    {
        title: 'Get advice with a reason',
        text: 'For each product: how much to order and by when, a risk level, and one plain sentence explaining why, from the forecast, the stock, the lead time and the safety stock.',
    },
    {
        title: 'Decide, and it is recorded',
        text: 'Accept, change the quantity or dismiss. Accepted orders count as on order until the goods arrive. Every decision keeps who, when and why.',
    },
];

const features: { icon: LucideIcon; title: string; text: string }[] = [
    {
        icon: TrendingUp,
        title: 'Sales forecasts',
        text: 'Weekly and monthly, with a range, accuracy you can check, and a flag on products with under a year of history.',
    },
    {
        icon: ClipboardCheck,
        title: 'Reorder advice',
        text: 'Reorder point, safety stock, order-up-to level, rounded to packs and minimum orders.',
    },
    {
        icon: Upload,
        title: 'Imports with a preview',
        text: 'Repeats skipped, problems listed row by row, an error report, and undo.',
    },
    {
        icon: Users,
        title: 'Three roles',
        text: 'Owner, Manager and Inventory staff each see and do only what they should.',
    },
    {
        icon: Bell,
        title: 'Alerts and digests',
        text: 'In the app and by email. Emails never carry anyone’s name or address.',
    },
    {
        icon: FileSpreadsheet,
        title: 'Reports',
        text: 'Sales, stock, forecast accuracy and decisions, on screen, in Excel and as PDF.',
    },
    {
        icon: LayoutDashboard,
        title: 'Dashboard',
        text: 'Sales, stock value, what needs ordering and how good the forecast has been, at a glance.',
    },
    {
        icon: ScrollText,
        title: 'Audit log',
        text: 'Who changed what, from what to what, and when. Read-only.',
    },
];

const stack = [
    'Laravel 13',
    'React 19',
    'Inertia',
    'Tailwind 4',
    'PostgreSQL 17',
    'Redis + Horizon',
    'Python FastAPI',
    'XGBoost',
    'Docker',
];

const roles: { name: string; text: string; fill: string }[] = [
    {
        name: 'Owner',
        text: 'Everything, plus people, system settings and the audit log.',
        fill: 'bg-overstock',
    },
    {
        name: 'Manager',
        text: 'Products, sales, forecasts, the decisions and all reports.',
        fill: 'bg-watch',
    },
    {
        name: 'Inventory staff',
        text: 'Deliveries, stock counts and sales; sees the advice and the stock report.',
        fill: 'bg-ok',
    },
];

const faqs: { q: string; a: string }[] = [
    {
        q: 'Does it place orders for me?',
        a: 'No. It advises; people decide. It never contacts suppliers, takes payments or changes anything without someone choosing to.',
    },
    {
        q: 'What data does it need?',
        a: 'Your products and your sales: date, product code, quantity and price. No customer details are ever stored. A year or more of sales gives the best forecasts.',
    },
    {
        q: 'How accurate is it?',
        a: 'It tells you. Every forecast is replayed on recent history and compared with “the same week last year” and “a recent average”; the error is shown as MAE, RMSE, MAPE and WAPE, overall and per category.',
    },
    {
        q: 'What about more than one branch?',
        a: 'Stock is already kept per location, so branches can be switched on later without moving any data. Today it runs one shop.',
    },
];

function Section({
    id,
    kicker,
    title,
    children,
}: {
    id?: string;
    kicker: string;
    title: string;
    children: ReactNode;
}) {
    return (
        <section id={id} className="scroll-mt-20 py-14 sm:py-20">
            <p className="font-mono text-xs font-semibold tracking-widest text-muted-foreground uppercase">
                {kicker}
            </p>
            <h2 className="mt-2 text-3xl font-bold tracking-tight sm:text-4xl">
                <span className="highlight">{title}</span>
            </h2>
            <div className="mt-8">{children}</div>
        </section>
    );
}

/** The public front page: what StockSense AI is, for shop owners and for the curious. */
export default function Welcome({ demo }: Props) {
    return (
        <>
            <Head title="Know what to reorder" />

            <div className="min-h-svh bg-background bg-grid text-foreground">
                <header className="sticky top-0 z-20 border-b-2 border-border bg-background/90 backdrop-blur-sm">
                    <div className="mx-auto flex max-w-6xl items-center gap-3 px-4 py-3">
                        <a
                            href="#top"
                            className="flex items-center gap-2 font-bold tracking-tight"
                        >
                            <span className="relative flex size-9 items-center justify-center rounded-md border-2 border-ink bg-primary text-primary-foreground shadow-brutal-sm dark:border-transparent">
                                <AppLogoIcon className="size-5 fill-current" />
                                <span
                                    aria-hidden="true"
                                    className="absolute -top-2 -right-3 rotate-12 rounded-sm border-2 border-ink bg-accent px-0.5 font-mono text-[8px] leading-tight font-bold text-ink"
                                >
                                    AI
                                </span>
                            </span>
                            <span className="ml-1">StockSense AI</span>
                        </a>
                        <nav
                            aria-label="On this page"
                            className="ml-6 hidden gap-5 text-sm font-medium md:flex"
                        >
                            <a href="#how" className="hover:underline">
                                How it works
                            </a>
                            <a href="#features" className="hover:underline">
                                Features
                            </a>
                            <a
                                href="#under-the-hood"
                                className="hover:underline"
                            >
                                Under the hood
                            </a>
                            <a href="#faq" className="hover:underline">
                                FAQ
                            </a>
                        </nav>
                        <div className="ml-auto flex items-center gap-2">
                            <ThemeToggle />
                            <Button asChild size="sm">
                                <Link
                                    href={login()}
                                    data-test="landing-sign-in"
                                >
                                    Sign in
                                </Link>
                            </Button>
                        </div>
                    </div>
                </header>

                <main id="top" className="mx-auto max-w-6xl px-4">
                    <section className="grid items-center gap-10 py-14 sm:py-20 lg:grid-cols-[1.05fr_1fr]">
                        <div>
                            <span className="inline-block -rotate-2 rounded-md border-2 border-ink bg-accent px-2 py-0.5 font-mono text-xs font-bold text-ink uppercase">
                                For small shops · AI-assisted
                            </span>
                            <h1 className="mt-5 text-4xl leading-tight font-bold tracking-tight sm:text-5xl lg:text-6xl">
                                Know{' '}
                                <span className="highlight">
                                    what to reorder, how much, and when
                                </span>
                                .
                            </h1>
                            <p className="mt-5 max-w-xl text-lg text-muted-foreground">
                                StockSense AI forecasts each product’s sales
                                with machine learning and turns the forecast
                                into reorder advice you can read in one
                                sentence. It advises and never places orders:
                                you decide, and every decision is recorded.
                            </p>
                            <div className="mt-8 flex flex-wrap gap-3">
                                <Button asChild size="lg">
                                    <Link href={login()}>
                                        Sign in <ArrowRight />
                                    </Link>
                                </Button>
                                <Button asChild size="lg" variant="outline">
                                    <a href="#how">See how it works</a>
                                </Button>
                            </div>
                            {demo && (
                                <p
                                    className="mt-6 max-w-xl rounded-xl border-2 border-dashed border-border bg-card px-4 py-3 text-sm"
                                    data-test="demo-hint"
                                >
                                    <b>Trying it out?</b> Sign in as{' '}
                                    <code className="font-mono">
                                        owner@stocksense.test
                                    </code>
                                    ,{' '}
                                    <code className="font-mono">
                                        manager@stocksense.test
                                    </code>{' '}
                                    or{' '}
                                    <code className="font-mono">
                                        staff@stocksense.test
                                    </code>{' '}
                                    with the password{' '}
                                    <code className="font-mono">password</code>.
                                </p>
                            )}
                        </div>

                        <div className="relative">
                            <div className="rotate-1 overflow-hidden rounded-2xl border-2 border-ink bg-card shadow-brutal-lg dark:border-border">
                                <img
                                    src="/images/landing/dashboard-light.png"
                                    alt="The StockSense AI dashboard: sales for the last 30 days, stock value, 33 products needing ordering, a 16.3% typical forecast error, and weekly sales"
                                    className="block w-full dark:hidden"
                                    width={1280}
                                    height={800}
                                />
                                <img
                                    src="/images/landing/dashboard-dark.png"
                                    alt="The StockSense AI dashboard in dark mode"
                                    className="hidden w-full dark:block"
                                    width={1280}
                                    height={800}
                                />
                            </div>
                            <span className="absolute -top-4 -left-3 -rotate-6 rounded-lg border-2 border-ink bg-primary px-3 py-1 font-mono text-xs font-bold text-primary-foreground shadow-brutal-sm">
                                XGBoost forecast
                            </span>
                            <span className="absolute -right-2 -bottom-4 rotate-3 rounded-lg border-2 border-ink bg-secondary px-3 py-1 font-mono text-xs font-bold text-ink shadow-brutal-sm">
                                Advice, not orders
                            </span>
                        </div>
                    </section>

                    <Section
                        kicker="The problem"
                        title="Stock is a guessing game"
                    >
                        <div className="grid gap-5 md:grid-cols-3">
                            {problems.map(
                                ({ icon: Icon, title, text, fill }) => (
                                    <div
                                        key={title}
                                        className="rounded-2xl border-2 bg-card p-6 shadow-brutal"
                                    >
                                        <span
                                            className={`inline-flex size-11 items-center justify-center rounded-lg border-2 border-ink text-ink ${fill}`}
                                        >
                                            <Icon className="size-5" />
                                        </span>
                                        <h3 className="mt-4 text-lg font-bold">
                                            {title}
                                        </h3>
                                        <p className="mt-1 text-muted-foreground">
                                            {text}
                                        </p>
                                    </div>
                                ),
                            )}
                        </div>
                    </Section>

                    <Section
                        id="how"
                        kicker="From sales to a decision"
                        title="How it works"
                    >
                        <ol className="grid gap-5 md:grid-cols-2 lg:grid-cols-4">
                            {steps.map((step, index) => (
                                <li
                                    key={step.title}
                                    className="relative rounded-2xl border-2 bg-card p-6 pt-8 shadow-brutal"
                                >
                                    <span className="absolute -top-4 left-5 flex size-9 items-center justify-center rounded-lg border-2 border-ink bg-accent font-mono text-lg font-bold text-ink shadow-brutal-sm">
                                        {index + 1}
                                    </span>
                                    <h3 className="text-lg font-bold">
                                        {step.title}
                                    </h3>
                                    <p className="mt-2 text-sm text-muted-foreground">
                                        {step.text}
                                    </p>
                                </li>
                            ))}
                        </ol>
                        <p className="mt-8 max-w-3xl rounded-2xl border-2 border-ink bg-low p-5 text-ink shadow-brutal-sm dark:border-transparent">
                            <b>Example of the advice:</b> “Stock is expected to
                            run out before a new order could arrive: 62
                            available against 148 of expected demand over the
                            7-day lead time. Order 264 (11 packs of 24) now, to
                            bring stock up to 324.”
                        </p>
                    </Section>

                    <Section
                        id="features"
                        kicker="Features"
                        title="Everything a small shop needs, nothing it does not"
                    >
                        <div className="grid gap-5 sm:grid-cols-2 lg:grid-cols-4">
                            {features.map(({ icon: Icon, title, text }) => (
                                <div
                                    key={title}
                                    className="rounded-2xl border-2 bg-card p-5 shadow-brutal-sm"
                                >
                                    <Icon className="size-6 text-primary" />
                                    <h3 className="mt-3 font-bold">{title}</h3>
                                    <p className="mt-1 text-sm text-muted-foreground">
                                        {text}
                                    </p>
                                </div>
                            ))}
                        </div>
                    </Section>

                    <Section
                        id="under-the-hood"
                        kicker="For the curious"
                        title="Under the hood"
                    >
                        <div className="grid gap-5 lg:grid-cols-2">
                            <div className="rounded-2xl border-2 bg-card p-6 shadow-brutal">
                                <div className="flex items-center gap-3">
                                    <BrainCircuit className="size-6 text-primary" />
                                    <h3 className="text-lg font-bold">
                                        The forecast
                                    </h3>
                                </div>
                                <ul className="mt-3 list-disc space-y-1.5 pl-5 text-sm text-muted-foreground">
                                    <li>
                                        One pooled{' '}
                                        <b className="text-foreground">
                                            XGBoost
                                        </b>{' '}
                                        quantile model across all products: the
                                        median and a 10th–90th percentile range.
                                    </li>
                                    <li>
                                        Features from past sales only (lags,
                                        rolling averages), the calendar,
                                        paydays, December and the back-to-school
                                        season.
                                    </li>
                                    <li>
                                        Checked by a rolling-origin backtest
                                        against “same week last year” and
                                        “recent average”, with MAE, RMSE, MAPE
                                        and WAPE.
                                    </li>
                                    <li>
                                        Products with under a year of history
                                        fall back to an average and are flagged
                                        low confidence.
                                    </li>
                                </ul>
                            </div>
                            <div className="rounded-2xl border-2 bg-card p-6 shadow-brutal">
                                <div className="flex items-center gap-3">
                                    <ShieldCheck className="size-6 text-primary" />
                                    <h3 className="text-lg font-bold">
                                        Built to be trusted
                                    </h3>
                                </div>
                                <ul className="mt-3 list-disc space-y-1.5 pl-5 text-sm text-muted-foreground">
                                    <li>
                                        No customer data, and emails without
                                        names or addresses, in line with the
                                        Data Privacy Act (RA 10173).
                                    </li>
                                    <li>
                                        Roles checked on the server, rate
                                        limits, a content security policy, and
                                        an audit log of every change.
                                    </li>
                                    <li>
                                        More than 1,500 automated tests, from
                                        business rules to accessibility in light
                                        and dark mode, evaluated against ISO/IEC
                                        25010.
                                    </li>
                                </ul>
                            </div>
                        </div>
                        <ul
                            aria-label="Built with"
                            className="mt-6 flex flex-wrap gap-2"
                        >
                            {stack.map((item) => (
                                <li
                                    key={item}
                                    className="rounded-full border-2 border-border bg-card px-3 py-1 font-mono text-xs font-semibold"
                                >
                                    {item}
                                </li>
                            ))}
                        </ul>
                    </Section>

                    <Section kicker="Who uses it" title="One shop, three roles">
                        <div className="grid gap-5 md:grid-cols-3">
                            {roles.map((role) => (
                                <div
                                    key={role.name}
                                    className="rounded-2xl border-2 bg-card p-6 shadow-brutal-sm"
                                >
                                    <span
                                        className={`inline-block rounded-full border-2 border-ink px-3 py-0.5 text-sm font-bold text-ink ${role.fill}`}
                                    >
                                        {role.name}
                                    </span>
                                    <p className="mt-3 text-muted-foreground">
                                        {role.text}
                                    </p>
                                </div>
                            ))}
                        </div>
                    </Section>

                    <Section id="faq" kicker="Questions" title="Good to know">
                        <div className="grid gap-4 md:grid-cols-2">
                            {faqs.map((faq) => (
                                <details
                                    key={faq.q}
                                    className="group rounded-2xl border-2 bg-card p-5 shadow-brutal-sm open:shadow-brutal"
                                >
                                    <summary className="cursor-pointer font-bold marker:text-primary">
                                        {faq.q}
                                    </summary>
                                    <p className="mt-2 text-muted-foreground">
                                        {faq.a}
                                    </p>
                                </details>
                            ))}
                        </div>
                    </Section>

                    <section className="py-14 sm:py-20">
                        <div className="rounded-3xl border-2 border-ink bg-primary p-8 text-primary-foreground shadow-brutal-lg sm:p-12 dark:border-transparent">
                            <h2 className="text-3xl font-bold tracking-tight sm:text-4xl">
                                Ready when you are.
                            </h2>
                            <p className="mt-3 max-w-xl text-lg opacity-90">
                                Sign in to see today’s advice, or ask the shop’s
                                Owner for an account.
                            </p>
                            <Button
                                asChild
                                size="lg"
                                variant="outline"
                                className="mt-6 text-foreground"
                            >
                                <Link href={login()}>
                                    Sign in <ArrowRight />
                                </Link>
                            </Button>
                        </div>
                    </section>
                </main>

                <footer className="border-t-2 border-border">
                    <div className="mx-auto flex max-w-6xl flex-wrap items-center justify-between gap-3 px-4 py-6 text-sm text-muted-foreground">
                        <span>
                            <b className="text-foreground">StockSense AI</b> ·
                            AI-assisted sales forecasting and replenishment
                            advice
                        </span>
                        <span>Advisory only: it never places orders.</span>
                    </div>
                </footer>
            </div>
        </>
    );
}
