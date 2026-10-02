import { Badge } from '@/components/ui/badge';
import { riskStyles } from '@/lib/replenishment';
import type { RiskLevel } from '@/types';

type Props = { risk: RiskLevel; label: string };

/** A risk level in its colour; hovering says what it means. */
export default function RiskBadge({ risk, label }: Props) {
    const style = riskStyles[risk];

    return (
        <Badge
            variant="outline"
            className={style.className}
            title={style.meaning}
            data-test="risk-badge"
        >
            {label}
        </Badge>
    );
}
