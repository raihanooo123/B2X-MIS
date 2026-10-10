{{-- 05.17 — a credit note, printed from the payload fixed at issue (CreditNoteDocumentBuilder). --}}
@extends('documents.layout')

@section('document')
    <div class="top">
        <div>
            <h1>{{ $doc['title'] }}</h1>
            <div class="mono">{{ $doc['number'] }}</div>
        </div>
        <table class="meta">
            <tr><td>Date</td><td>{{ $doc['issued_on_display'] }}</td></tr>
            <tr><td>Reason</td><td>{{ $doc['reason_label'] }}</td></tr>
            @if (! empty($doc['original_invoice']))
                <tr><td>Original invoice</td><td>{{ $doc['original_invoice']['number'] }} ({{ $doc['original_invoice']['issued_on_display'] }})</td></tr>
            @endif
            @if (! empty($doc['order_number']))
                <tr><td>Order</td><td>{{ $doc['order_number'] }}</td></tr>
            @endif
            @if (! empty($doc['customer_reference']))
                <tr><td>Your reference</td><td>{{ $doc['customer_reference'] }}</td></tr>
            @endif
        </table>
    </div>

    <div class="parties">
        @include('documents.partials.party', ['label' => 'From', 'party' => $doc['seller'] ?? []])
        @include('documents.partials.party', ['label' => 'To', 'party' => $doc['customer'] ?? []])
    </div>

    <table class="totals" style="width: 100%;">
        <tr><td class="muted">Net credited</td><td class="num">{{ $doc['totals']['net'] }}</td></tr>
        <tr><td class="muted">VAT credited</td><td class="num">{{ $doc['totals']['vat'] }}</td></tr>
        <tr class="grand"><td>Total credit</td><td class="num">{{ $doc['totals']['total_gross'] }}</td></tr>
        <tr><td class="muted">Set against the original invoice</td><td class="num">{{ $doc['totals']['allocated_to_invoice'] }}</td></tr>
        <tr><td class="muted">Added to your account balance</td><td class="num">{{ $doc['totals']['to_account_balance'] }}</td></tr>
    </table>

    @include('documents.partials.footer')
@endsection
