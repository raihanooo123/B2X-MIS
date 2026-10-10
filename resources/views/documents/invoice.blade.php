{{-- 05.17 — a VAT invoice or receipt, printed from the payload fixed at issue (InvoiceDocumentBuilder). --}}
@extends('documents.layout')

@section('document')
    <div class="top">
        <div>
            <h1>{{ $doc['title'] }}</h1>
            <div class="mono">{{ $doc['number'] }}</div>
        </div>
        <table class="meta">
            <tr><td>Date</td><td>{{ $doc['issued_on_display'] ?? $doc['issued_on'] }}</td></tr>
            @if (($doc['kind'] ?? '') === 'vat_invoice')
                <tr><td>Tax point</td><td>{{ $doc['issued_on_display'] ?? $doc['tax_point'] }}</td></tr>
            @endif
            @if (! empty($doc['due_on_display']))
                <tr><td>Due</td><td><strong>{{ $doc['due_on_display'] }}</strong></td></tr>
            @endif
            @if (! empty($doc['payment_terms_label']))
                <tr><td>Terms</td><td>{{ $doc['payment_terms_label'] }}</td></tr>
            @endif
            <tr><td>Order</td><td>{{ $doc['order_number'] }}</td></tr>
            @if (! empty($doc['customer_reference']))
                <tr><td>Your reference</td><td>{{ $doc['customer_reference'] }}</td></tr>
            @endif
        </table>
    </div>

    <div class="parties">
        @include('documents.partials.party', ['label' => 'From', 'party' => $doc['seller'] ?? []])
        @include('documents.partials.party', ['label' => 'Bill to', 'party' => $doc['customer'] ?? []])
        @if (! empty($doc['delivery_address_lines'] ?? []))
            @include('documents.partials.party', ['label' => 'Deliver to', 'party' => ['address_lines' => $doc['delivery_address_lines']]])
        @endif
    </div>

    <table class="lines">
        <thead>
            <tr>
                <th>Item</th>
                <th>Quantity</th>
                <th class="num">Unit net</th>
                <th class="num">VAT rate</th>
                <th class="num">Net</th>
                <th class="num">VAT</th>
            </tr>
        </thead>
        <tbody>
            @foreach ($doc['lines'] ?? [] as $line)
                <tr>
                    <td>{{ $line['description'] }}<br><span class="mono muted">{{ $line['sku_code'] }}</span></td>
                    <td>
                        @if ($line['pack_qty'] !== null)
                            {{ $line['pack_qty'] }} × {{ $line['pack_label'] }}<br>
                        @endif
                        <span class="muted">{{ number_format($line['base_qty']) }} units</span>
                    </td>
                    <td class="num">{{ $line['unit_price_net'] }}</td>
                    <td class="num">{{ $line['vat_rate'] }}</td>
                    <td class="num">{{ $line['line_net'] }}</td>
                    <td class="num">{{ $line['line_vat'] }}</td>
                </tr>
            @endforeach
            @if (! empty($doc['carriage']))
                <tr>
                    <td colspan="3">{{ $doc['carriage']['description'] }}</td>
                    <td class="num">{{ $doc['carriage']['vat_rate'] }}</td>
                    <td class="num">{{ $doc['carriage']['net'] }}</td>
                    <td class="num">{{ $doc['carriage']['vat'] }}</td>
                </tr>
            @endif
        </tbody>
    </table>

    <table class="totals">
        @foreach ($doc['vat_summary'] ?? [] as $rate)
            <tr><td class="muted">Net at {{ $rate['rate'] }}</td><td class="num">{{ $rate['net'] }}</td></tr>
            <tr><td class="muted">VAT at {{ $rate['rate'] }}</td><td class="num">{{ $rate['vat'] }}</td></tr>
        @endforeach
        @if (($doc['totals']['discount_net_minor'] ?? 0) > 0)
            <tr><td class="muted">Discount (included above)</td><td class="num">{{ $doc['totals']['discount_net'] }}</td></tr>
        @endif
        <tr class="grand"><td>Total</td><td class="num">{{ $doc['totals']['total_gross'] }}</td></tr>
        @if (($doc['totals']['paid_minor'] ?? 0) > 0)
            <tr><td class="muted">Paid</td><td class="num">{{ $doc['totals']['paid'] }}</td></tr>
            <tr><td><strong>Balance due</strong></td><td class="num"><strong>{{ $doc['totals']['balance_due'] }}</strong></td></tr>
        @endif
    </table>

    @include('documents.partials.footer')
@endsection
