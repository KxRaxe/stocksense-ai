<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <title>{{ $title }}</title>
    <style>
        /* The app's neo-brutalist look in what dompdf draws: ink outlines, a thick
           bottom-right edge for the hard shadow (no box-shadow here), violet and
           yellow blocks. DejaVu has the peso sign. */
        @page { margin: 28px 30px 40px 30px; }
        body { font-family: 'DejaVu Sans', sans-serif; font-size: 8.5px; color: #121524; }
        .brand { margin-bottom: 10px; }
        .brand .logo { background: #6639ee; color: #ffffff; border: 1.5px solid #121524; padding: 2px 6px; font-weight: bold; font-size: 9px; }
        .brand .tag { background: #f7ea3c; color: #121524; border: 1.5px solid #121524; padding: 1px 3px; font-family: 'DejaVu Sans Mono', monospace; font-weight: bold; font-size: 7px; }
        h1 { display: inline; font-size: 17px; margin: 0; background: #f7ea3c; padding: 0 4px; }
        .meta { font-size: 9px; color: #535769; margin: 6px 0 12px 0; }
        .summary { width: 100%; border-collapse: separate; border-spacing: 6px 0; margin: 0 -6px 14px -6px; }
        .summary td { border: 1.5px solid #121524; border-right-width: 3.5px; border-bottom-width: 3.5px; background: #ffffff; padding: 6px 8px; width: 20%; vertical-align: top; }
        .summary .label { font-size: 6.5px; color: #535769; text-transform: uppercase; letter-spacing: 0.4px; font-weight: bold; }
        .summary .value { font-family: 'DejaVu Sans Mono', monospace; font-size: 12px; font-weight: bold; margin-top: 3px; }
        table.data { width: 100%; border-collapse: collapse; border: 1.5px solid #121524; }
        table.data th { background: #121524; color: #f9f5e6; text-align: left; padding: 4px 5px; font-size: 7px; text-transform: uppercase; letter-spacing: 0.3px; }
        table.data td { padding: 3px 5px; border-bottom: 0.5px solid #d9d3bf; vertical-align: top; }
        table.data tbody tr:nth-child(even) td { background: #f9f5e6; }
        table.data tr.total td { font-weight: bold; background: #f7ea3c; border-top: 1.5px solid #121524; border-bottom: none; }
        .num { text-align: right; white-space: nowrap; font-family: 'DejaVu Sans Mono', monospace; }
        thead { display: table-header-group; }
        tr { page-break-inside: avoid; }
        .notes { margin-top: 12px; font-size: 8px; color: #535769; }
        .notes li { margin-bottom: 3px; }
        .empty { padding: 18px; text-align: center; color: #535769; border: 1.5px dashed #121524; }
        .footer { position: fixed; bottom: -26px; left: 0; right: 0; font-size: 7.5px; color: #535769; border-top: 1px solid #121524; padding-top: 3px; }
        .footer .page:after { content: "Page " counter(page); }
        .footer .page { float: right; font-family: 'DejaVu Sans Mono', monospace; }
    </style>
</head>
<body>
    <div class="footer">
        <span>StockSense AI &middot; {{ $title }} &middot; made {{ $generatedAt }}</span>
        <span class="page"></span>
    </div>

    <div class="brand"><span class="logo">StockSense</span> <span class="tag">AI</span></div>
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
