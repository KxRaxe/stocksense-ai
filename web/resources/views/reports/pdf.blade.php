<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <title>{{ $title }}</title>
    <style>
        @page { margin: 28px 30px 40px 30px; }
        body { font-family: 'DejaVu Sans', sans-serif; font-size: 8.5px; color: #1f2937; }
        h1 { font-size: 16px; margin: 0 0 2px 0; }
        .brand { font-size: 8px; color: #6b7280; margin-bottom: 8px; }
        .meta { font-size: 9px; color: #4b5563; margin: 0 0 10px 0; }
        .summary { width: 100%; border-collapse: separate; border-spacing: 6px 0; margin: 0 -6px 12px -6px; }
        .summary td { border: 1px solid #d1d5db; padding: 6px 8px; width: 20%; vertical-align: top; }
        .summary .label { font-size: 7.5px; color: #6b7280; }
        .summary .value { font-size: 12px; font-weight: bold; margin-top: 2px; }
        table.data { width: 100%; border-collapse: collapse; }
        table.data th { background: #e5e7eb; text-align: left; padding: 4px 5px; border-bottom: 1px solid #9ca3af; font-size: 8px; }
        table.data td { padding: 3px 5px; border-bottom: 1px solid #e5e7eb; vertical-align: top; }
        table.data tr.total td { font-weight: bold; border-top: 1px solid #6b7280; border-bottom: none; }
        .num { text-align: right; white-space: nowrap; }
        thead { display: table-header-group; }
        tr { page-break-inside: avoid; }
        .notes { margin-top: 12px; font-size: 8px; color: #4b5563; }
        .notes li { margin-bottom: 3px; }
        .empty { padding: 18px; text-align: center; color: #6b7280; border: 1px solid #d1d5db; }
        .footer { position: fixed; bottom: -26px; left: 0; right: 0; font-size: 7.5px; color: #6b7280; }
        .footer .page:after { content: "Page " counter(page); }
        .footer .page { float: right; }
    </style>
</head>
<body>
    <div class="footer">
        <span>StockSense AI &middot; {{ $title }} &middot; made {{ $generatedAt }}</span>
        <span class="page"></span>
    </div>

    <div class="brand">StockSense AI</div>
    <h1>{{ $title }}</h1>
    @if ($filters !== '')
        <p class="meta">{{ $filters }}</p>
    @endif

    @if (count($summary) > 0)
        <table class="summary">
            <tr>
                @foreach ($summary as $figure)
                    <td>
                        <div class="label">{{ $figure['label'] }}</div>
                        <div class="value">{{ $figure['text'] }}</div>
                    </td>
                @endforeach
            </tr>
        </table>
    @endif

    @if (count($rows) === 0)
        <div class="empty">Nothing to show for this period.</div>
    @else
        <table class="data">
            <thead>
                <tr>
                    @foreach ($columns as $column)
                        <th class="{{ $column['numeric'] ? 'num' : '' }}">{{ $column['label'] }}</th>
                    @endforeach
                </tr>
            </thead>
            <tbody>
                @foreach ($rows as $row)
                    <tr>
                        @foreach ($columns as $column)
                            <td class="{{ $column['numeric'] ? 'num' : '' }}">{{ $row[$column['key']] }}</td>
                        @endforeach
                    </tr>
                @endforeach
                @if ($totals !== null)
                    <tr class="total">
                        @foreach ($columns as $column)
                            <td class="{{ $column['numeric'] ? 'num' : '' }}">{{ $totals[$column['key']] }}</td>
                        @endforeach
                    </tr>
                @endif
            </tbody>
        </table>
    @endif

    @if ($truncated !== null)
        <p class="notes"><strong>Only the first {{ number_format($truncated['shown']) }} of {{ number_format($truncated['total']) }} rows are shown here.</strong> The Excel export has all of them.</p>
    @endif

    @if (count($notes) > 0)
        <ul class="notes">
            @foreach ($notes as $note)
                <li>{{ $note }}</li>
            @endforeach
        </ul>
    @endif
</body>
</html>
