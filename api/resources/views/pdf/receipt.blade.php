{{-- Receipt PDF (PRD-05 FR-15). A document template, not a page: the API serves no UI. --}}
<!doctype html>
<html lang="{{ $locale }}">
<head>
    <meta charset="utf-8">
    <title>{{ __('receipt.title', [], $locale) }} {{ $receipt['number'] }}</title>
    <style>
        body { font-family: DejaVu Sans, sans-serif; font-size: 11px; color: #0f172a; }
        h1 { color: #2c3892; font-size: 20px; margin: 0 0 4px; }
        .muted { color: #475569; }
        table { width: 100%; border-collapse: collapse; margin-top: 16px; }
        th, td { padding: 6px 4px; border-bottom: 1px solid #e2e8f0; text-align: left; }
        td.num, th.num { text-align: right; }
        .total td { font-weight: bold; border-top: 2px solid #2c3892; }
    </style>
</head>
<body>
    <h1>Yekkola</h1>
    <div class="muted">{{ __('receipt.title', [], $locale) }} · {{ $receipt['number'] }}</div>
    <p>
        {{ __('receipt.date', [], $locale) }} : {{ \Illuminate\Support\Carbon::parse($receipt['issued_at'])->locale($locale)->isoFormat('LL') }}<br>
        @if ($receipt['student']){{ __('receipt.student', [], $locale) }} : {{ $receipt['student'] }}<br>@endif
        @if ($receipt['payment'])
            {{ __('receipt.paid_with', [], $locale) }} : {{ __('receipt.rails.'.$receipt['payment']['rail'], [], $locale) }} ({{ $receipt['payment']['payer'] }})<br>
            {{ __('receipt.reference', [], $locale) }} : {{ $receipt['payment']['operator_reference'] ?? $receipt['payment']['reference'] }}
        @endif
    </p>
    <table>
        <thead>
            <tr><th>{{ __('receipt.course', [], $locale) }}</th><th class="num">{{ __('receipt.price', [], $locale) }}</th><th class="num">{{ __('receipt.discount', [], $locale) }}</th><th class="num">{{ __('receipt.paid', [], $locale) }}</th></tr>
        </thead>
        <tbody>
            @foreach ($receipt['items'] as $item)
                <tr>
                    <td>{{ $item['title'] }} @if ($item['refunded'])<span class="muted">({{ __('receipt.refunded', [], $locale) }})</span>@endif</td>
                    <td class="num">{{ $item['list_price']['formatted'] }}</td>
                    <td class="num">{{ $item['discount']['amount_minor'] > 0 ? '−'.$item['discount']['formatted'] : '' }}</td>
                    <td class="num">{{ $item['price']['formatted'] }}</td>
                </tr>
            @endforeach
            <tr class="total"><td colspan="3">{{ __('receipt.total', [], $locale) }}</td><td class="num">{{ $receipt['total']['formatted'] }}</td></tr>
            @if ($receipt['refunded']['amount_minor'] > 0)
                <tr><td colspan="3">{{ __('receipt.refunded_total', [], $locale) }}</td><td class="num">−{{ $receipt['refunded']['formatted'] }}</td></tr>
            @endif
        </tbody>
    </table>
    <p class="muted">{{ __('receipt.footer', [], $locale) }}</p>
</body>
</html>
