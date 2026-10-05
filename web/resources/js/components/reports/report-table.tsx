import {
    Table,
    TableBody,
    TableCell,
    TableHead,
    TableHeader,
    TableRow,
} from '@/components/ui/table';
import { formatCell, isNumeric } from '@/lib/reports';
import { cn } from '@/lib/utils';
import type { ReportColumnDef, ReportRow } from '@/types';

type Props = {
    columns: ReportColumnDef[];
    rows: ReportRow[];
    /** A totals row for the whole report, shown under the last page. */
    totals: ReportRow | null;
    /** Whether this is the last page, where the totals belong. */
    showTotals: boolean;
};

/** A report's table: numbers on the right, gaps as dashes, and the totals under the last page. */
export default function ReportTable({
    columns,
    rows,
    totals,
    showTotals,
}: Props) {
    return (
        <div className="overflow-x-auto rounded-xl border-2 bg-card shadow-brutal">
            <Table data-test="report-table">
                <TableHeader>
                    <TableRow>
                        {columns.map((column) => (
                            <TableHead
                                key={column.key}
                                className={cn(
                                    'whitespace-nowrap',
                                    isNumeric(column.type) && 'text-right',
                                )}
                            >
                                {column.label}
                            </TableHead>
                        ))}
                    </TableRow>
                </TableHeader>
                <TableBody>
                    {rows.length === 0 && (
                        <TableRow>
                            <TableCell
                                colSpan={columns.length}
                                className="p-8 text-center text-muted-foreground"
                                data-test="no-rows"
                            >
                                Nothing to show for this period.
                            </TableCell>
                        </TableRow>
                    )}
                    {rows.map((row, index) => (
                        <TableRow key={index} data-test="report-row">
                            {columns.map((column) => (
                                <TableCell
                                    key={column.key}
                                    className={cn(
                                        isNumeric(column.type)
                                            ? 'text-right'
                                            : 'max-w-64 truncate',
                                    )}
                                >
                                    {formatCell(row[column.key], column.type)}
                                </TableCell>
                            ))}
                        </TableRow>
                    ))}
                </TableBody>
                {totals && showTotals && rows.length > 0 && (
                    <tfoot className="border-t bg-muted/50">
                        <TableRow data-test="report-totals">
                            {columns.map((column) => (
                                <TableCell
                                    key={column.key}
                                    className={cn(
                                        'font-medium',
                                        isNumeric(column.type) && 'text-right',
                                    )}
                                >
                                    {totals[column.key] === undefined ||
                                    totals[column.key] === null
                                        ? ''
                                        : formatCell(
                                              totals[column.key],
                                              column.type,
                                          )}
                                </TableCell>
                            ))}
                        </TableRow>
                    </tfoot>
                )}
            </Table>
        </div>
    );
}
