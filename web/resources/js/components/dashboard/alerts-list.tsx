import { Link } from '@inertiajs/react';
import RiskBadge from '@/components/recommendations/risk-badge';
import { Button } from '@/components/ui/button';
import { formatNumber, formatUnits } from '@/lib/format';
import { index } from '@/routes/recommendations';
import type { DashboardAlert } from '@/types';

type Props = {
    alerts: DashboardAlert[];
    /** How many products need ordering in all, of which these are the most urgent. */
    total: number;
};

/** The products most in need of an order, each with how much and why. */
export default function AlertsList({ alerts, total }: Props) {
    if (alerts.length === 0) {
        return (
            <p className="text-sm text-muted-foreground" data-test="no-alerts">
                Nothing needs ordering right now.
            </p>
        );
    }

    return (
        <div className="space-y-3">
            <ul className="divide-y rounded-lg border" data-test="alerts">
                {alerts.map((alert) => (
                    <li
                        key={alert.id}
                        className="space-y-1 p-3 text-sm"
                        data-test={`alert-${alert.id}`}
                    >
                        <div className="flex items-start justify-between gap-2">
                            <span className="font-medium">
                                {alert.product.name}
                            </span>
                            <RiskBadge
                                risk={alert.risk}
                                label={alert.risk_label}
                            />
                        </div>
                        <p className="text-muted-foreground">
                            {formatNumber(alert.on_hand + alert.on_order)}{' '}
                            {alert.product.unit} available against{' '}
                            {formatUnits(alert.lead_time_demand)} expected to
                            sell while an order arrives. Order{' '}
                            {formatNumber(alert.recommended_qty)}.
                        </p>
                    </li>
                ))}
            </ul>
            <Button variant="outline" size="sm" asChild>
                <Link href={index()} data-test="see-recommendations">
                    {total > alerts.length
                        ? `See all ${total} recommendations`
                        : 'See the recommendations'}
                </Link>
            </Button>
        </div>
    );
}
