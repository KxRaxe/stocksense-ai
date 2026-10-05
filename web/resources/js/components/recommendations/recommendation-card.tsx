import { Link } from '@inertiajs/react';
import { useState } from 'react';
import DecisionDialog from '@/components/recommendations/decision-dialog';
import RiskBadge from '@/components/recommendations/risk-badge';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { formatNumber, formatUnits } from '@/lib/format';
import { decisionSummary, headline, orderingRules } from '@/lib/replenishment';
import { show } from '@/routes/products';
import type { DecisionKind, RecommendationRow } from '@/types';

type Props = {
    row: RecommendationRow;
    /** Whether the person may accept, adjust, dismiss and cancel. */
    canDecide: boolean;
};

function Figure({
    label,
    value,
    testId,
}: {
    label: string;
    value: string;
    testId: string;
}) {
    return (
        <div>
            <dt className="text-xs text-muted-foreground">{label}</dt>
            <dd className="font-medium" data-test={testId}>
                {value}
            </dd>
        </div>
    );
}

/**
 * One product's recommendation: how much to order and by when, the figures
 * behind it, the reasoning in plain words, and what can be done about it.
 */
export default function RecommendationCard({ row, canDecide }: Props) {
    const [deciding, setDeciding] = useState<DecisionKind | null>(null);

    const unit = row.product.unit;
    const summary = headline(row);
    const rules = orderingRules(row.product.pack_size, row.product.moq, unit);
    const decided = decisionSummary(row);

    return (
        <article
            className="space-y-4 rounded-xl border-2 bg-card p-4 shadow-brutal"
            data-test={`recommendation-${row.id}`}
        >
            <div className="flex flex-wrap items-start justify-between gap-3">
                <div>
                    <Link
                        href={show(row.product.id)}
                        className="font-medium underline-offset-4 hover:underline"
                    >
                        {row.product.name}
                    </Link>
                    <div className="text-xs text-muted-foreground">
                        <span className="font-mono">{row.product.sku}</span> ·{' '}
                        {row.product.category}
                    </div>
                </div>

                <div className="flex items-center gap-2">
                    {!row.is_pending && row.status !== 'info' && (
                        <Badge variant="secondary" data-test="status-badge">
                            {row.status_label}
                        </Badge>
                    )}
                    <RiskBadge risk={row.risk} label={row.risk_label} />
                </div>
            </div>

            <div>
                <p className="text-lg font-semibold" data-test="order-summary">
                    {summary.main}
                    {summary.detail && (
                        <span className="font-normal text-muted-foreground">
                            {' · '}
                            {summary.detail}
                        </span>
                    )}
                </p>
                {rules && row.recommended_qty > 0 && row.is_pending && (
                    <p className="text-xs text-muted-foreground">{rules}</p>
                )}
            </div>

            <dl className="grid grid-cols-2 gap-x-4 gap-y-3 text-sm sm:grid-cols-3 lg:grid-cols-6">
                <Figure
                    label="On hand"
                    value={`${formatNumber(row.on_hand)} ${unit}`}
                    testId="on-hand"
                />
                <Figure
                    label="On order"
                    value={`${formatNumber(row.on_order)} ${unit}`}
                    testId="on-order"
                />
                <Figure
                    label={`Demand in ${row.lead_time_days} days`}
                    value={`${formatUnits(row.lead_time_demand)} ${unit}`}
                    testId="lead-time-demand"
                />
                <Figure
                    label="Safety stock"
                    value={`${formatNumber(row.safety_stock)} ${unit}`}
                    testId="safety-stock"
                />
                <Figure
                    label="Reorder point"
                    value={`${formatNumber(row.reorder_point)} ${unit}`}
                    testId="reorder-point"
                />
                <Figure
                    label="Days of cover"
                    value={
                        row.days_of_cover === null
                            ? '-'
                            : formatNumber(Math.round(row.days_of_cover))
                    }
                    testId="days-of-cover"
                />
            </dl>

            {!row.is_pending && row.status !== 'info' && (
                <p
                    className="text-xs text-muted-foreground"
                    data-test="snapshot-note"
                >
                    Figures are as they were when this was recommended.
                </p>
            )}

            <p
                className="text-sm text-muted-foreground"
                data-test="explanation"
            >
                {row.explanation}
            </p>

            {decided && (
                <div
                    className="rounded-md bg-muted/50 p-3 text-sm"
                    data-test="decision-summary"
                >
                    <p>{decided}</p>
                    {row.note && (
                        <p className="mt-1 text-muted-foreground">
                            Note: {row.note}
                        </p>
                    )}
                </div>
            )}

            {canDecide && (row.is_pending || row.is_ordered) && (
                <div className="flex flex-wrap gap-2">
                    {row.is_pending && (
                        <>
                            <Button
                                size="sm"
                                onClick={() => setDeciding('accept')}
                                data-test="accept-button"
                            >
                                Accept
                            </Button>
                            <Button
                                size="sm"
                                variant="outline"
                                onClick={() => setDeciding('adjust')}
                                data-test="adjust-button"
                            >
                                Change quantity
                            </Button>
                            <Button
                                size="sm"
                                variant="ghost"
                                onClick={() => setDeciding('dismiss')}
                                data-test="dismiss-button"
                            >
                                Dismiss
                            </Button>
                        </>
                    )}
                    {row.is_ordered && (
                        <Button
                            size="sm"
                            variant="outline"
                            onClick={() => setDeciding('cancel')}
                            data-test="cancel-button"
                        >
                            Cancel order
                        </Button>
                    )}
                </div>
            )}

            <DecisionDialog
                kind={deciding}
                row={row}
                onClose={() => setDeciding(null)}
            />
        </article>
    );
}
