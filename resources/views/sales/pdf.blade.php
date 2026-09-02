<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <title>{{ $sale->invoice_number }}</title>
    <style>
        /* dompdf has no Tailwind — plain CSS only. */
        * { box-sizing: border-box; }
        body { font-family: DejaVu Sans, sans-serif; font-size: 11px; color: #0f172a; margin: 0; padding: 28px; }
        h1 { font-size: 18px; margin: 0; }
        .muted { color: #64748b; }
        .row { width: 100%; }
        .row td { vertical-align: top; }
        .head { border-bottom: 3px solid #0f172a; padding-bottom: 12px; }
        .meta { width: 100%; margin: 16px 0; }
        .meta td { padding: 3px 0; font-size: 11px; }
        .label { color: #64748b; text-transform: uppercase; font-size: 9px; letter-spacing: .5px; }
        table.lines { width: 100%; border-collapse: collapse; margin-top: 8px; }
        table.lines th { text-align: left; font-size: 9px; text-transform: uppercase; letter-spacing: .5px;
                         color: #64748b; border-bottom: 2px solid #e2e8f0; padding: 7px 0; }
        table.lines td { padding: 7px 0; border-bottom: 1px solid #f1f5f9; }
        .right { text-align: right; }
        .totals { width: 240px; margin-left: auto; margin-top: 14px; border-collapse: collapse; }
        .totals td { padding: 4px 0; }
        .grand td { border-top: 3px solid #0f172a; padding-top: 8px; font-size: 16px; font-weight: bold; }
        .foot { margin-top: 28px; border-top: 1px solid #e2e8f0; padding-top: 10px;
                text-align: center; font-size: 9px; color: #94a3b8; }
    </style>
</head>
<body>

<table class="row head">
    <tr>
        <td>
            <h1>{{ config('app.name') }}</h1>
            <div class="muted">Oil change &amp; auto repair</div>
        </td>
        <td class="right">
            <div class="label">Invoice</div>
            <div style="font-size:14px;font-weight:bold;">{{ $sale->invoice_number }}</div>
            <div class="muted">{{ $sale->created_at->format('d M Y, g:i A') }}</div>
        </td>
    </tr>
</table>

<table class="meta">
    @foreach ([
        'Customer' => $sale->customer_name ?: 'Walk-in',
        'Mobile' => $sale->phone ?: '—',
        'Vehicle' => $sale->vehicle_model ?: '—',
        'Plate' => $sale->vehicle_plate ?: '—',
        'Visit odometer reading' => $sale->mileage !== null ? number_format($sale->mileage).' km' : '—',
        'Next checkup mileage' => $sale->next_checkup_mileage !== null ? number_format($sale->next_checkup_mileage).' km' : '—',
        'Served by' => $sale->cashier?->name ?: '—',
    ] as $label => $value)
        <tr>
            <td class="label">{{ $label }}</td>
            <td class="right"><strong>{{ $value }}</strong></td>
        </tr>
    @endforeach
</table>

<table class="lines">
    <thead>
        <tr>
            <th>Description</th>
            <th>Type</th>
            <th class="right">Charged</th>
        </tr>
    </thead>
    <tbody>
        @forelse ($sale->lines as $line)
            <tr>
                <td><strong>{{ $line->item_name }}</strong>@if ($line->quantity > 1) <span class="muted">&times; {{ $line->quantity }}</span>@endif</td>
                <td class="muted">{{ $line->type->label() }}</td>
                <td class="right">{{ number_format((float) $line->manually_charged_price, 2) }}</td>
            </tr>
        @empty
            <tr><td colspan="3" class="muted">No line items — labor / misc only.</td></tr>
        @endforelse
    </tbody>
</table>

<table class="totals">
    <tr>
        <td class="muted">Items &amp; repairs</td>
        <td class="right">{{ number_format((float) $sale->lineSubtotal(), 2) }}</td>
    </tr>
    <tr>
        <td class="muted">Labor</td>
        <td class="right">{{ number_format((float) $sale->labor_charge, 2) }}</td>
    </tr>
    <tr>
        <td class="muted">Miscellaneous</td>
        <td class="right">{{ number_format((float) $sale->misc_charge, 2) }}</td>
    </tr>
    <tr class="grand">
        <td>TOTAL</td>
        <td class="right">{{ number_format((float) $sale->total_amount, 2) }}</td>
    </tr>
</table>

@if ($sale->notes)
    <p style="margin-top:18px;"><span class="label">Note:</span> {{ $sale->notes }}</p>
@endif

<div class="foot">Thank you for your business. Please keep this invoice for your service record.</div>

</body>
</html>
