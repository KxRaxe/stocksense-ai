import { Link } from '@inertiajs/react';
import {
    Table,
    TableBody,
    TableCell,
    TableHead,
    TableHeader,
    TableRow,
} from '@/components/ui/table';
import { formatMoney, formatNumber } from '@/lib/format';
import { show } from '@/routes/products';
import type { Mover, SlowMover } from '@/types';

type Props =
    | { kind: 'top'; rows: Mover[] }
    | { kind: 'slow'; rows: SlowMover[] };

function ProductLink({ id, name }: { id: number; name: string }) {
    return (
        <Link
            href={show(id)}
            className="font-medium underline-offset-4 hover:underline"
        >
            {name}
        </Link>
    );
}

/**
 * The fastest sellers, or the stock that is sitting. Quiet products appear in
 * the second with their units sold, which can be nothing.
 */
export default function MoversTable(props: Props) {
    if (props.rows.length === 0) {
        return (
            <p
                className="text-sm text-muted-foreground"
                data-test={`no-${props.kind}-movers`}
            >
                {props.kind === 'top'
                    ? 'Nothing has sold in this period.'
                    : 'No product is in stock.'}
            </p>
        );
    }

    return (
        <Table data-test={`${props.kind}-movers`}>
            <TableHeader>
                <TableRow>
                    <TableHead>Product</TableHead>
                    <TableHead className="text-right">Units sold</TableHead>
                    <TableHead className="text-right">
                        {props.kind === 'top' ? 'Revenue' : 'Stock value'}
                    </TableHead>
                </TableRow>
            </TableHeader>
            <TableBody>
                {props.kind === 'top'
                    ? props.rows.map((row) => (
                          <TableRow key={row.product_id}>
                              <TableCell>
                                  <ProductLink
                                      id={row.product_id}
                                      name={row.name}
                                  />
                                  <div className="text-xs text-muted-foreground">
                                      {row.category}
                                  </div>
                              </TableCell>
                              <TableCell className="text-right">
                                  {formatNumber(row.units)}
                              </TableCell>
                              <TableCell className="text-right">
                                  {formatMoney(row.revenue)}
                              </TableCell>
                          </TableRow>
                      ))
                    : props.rows.map((row) => (
                          <TableRow key={row.product_id}>
                              <TableCell>
                                  <ProductLink
                                      id={row.product_id}
                                      name={row.name}
                                  />
                                  <div className="text-xs text-muted-foreground">
                                      {formatNumber(row.on_hand)} {row.unit} on
                                      hand
                                  </div>
                              </TableCell>
                              <TableCell className="text-right">
                                  {formatNumber(row.units)}
                              </TableCell>
                              <TableCell className="text-right">
                                  {formatMoney(row.stock_value)}
                              </TableCell>
                          </TableRow>
                      ))}
            </TableBody>
        </Table>
    );
}
