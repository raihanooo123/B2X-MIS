{{-- 05.17 — an account statement, printed from the figures fixed at its cutoff (Statements). Debt and balance apart. --}}
@extends('documents.layout')

@section('document')
    <div class="top">
        <div>
            <h1>{{ $doc['title'] }}</h1>
            <div>{{ $doc['from_display'] }} – {{ $doc['to_display'] }}</div>
        </div>
        <table class="meta">
            <tr><td>Account</td><td>{{ $doc['customer']['account_code'] }}</td></tr>
            <tr><td>As at</td><td>{{ $doc['cutoff_display'] }} (UK time)</td></tr>
        </table>
    </div>

    <div class="parties">
        @include('documents.partials.party', ['label' => 'From', 'party' => $doc['seller'] ?? []])
        @include('documents.partials.party', ['label' => 'Statement for', 'party' => ['name' => $doc['customer']['name'], 'account_code' => $doc['customer']['account_code'], 'vat_number' => $doc['customer']['vat_number'], 'address_lines' => []]])
    </div>

    <h2>What you owe</h2>
    <div class="grid">
        <div class="box"><div class="label">Owed at the start</div>{{ $doc['debt']['opening'] }}</div>
        <div class="box"><div class="label">Invoiced</div>{{ $doc['debt']['invoiced'] }}</div>
        <div class="box"><div class="label">Paid</div>{{ $doc['debt']['cash'] }}</div>
        <div class="box"><div class="label">Credited</div>{{ $doc['debt']['credits'] }}</div>
        <div class="box"><div class="label">Owed at the end</div><strong>{{ $doc['debt']['closing'] }}</strong></div>
    </div>

    <table class="lines">
        <thead><tr><th>Date</th><th>Detail</th><th class="num">Charged</th><th class="num">Paid or credited</th></tr></thead>
        <tbody>
            @php
                $rows = [];
                foreach ($doc['debt']['invoices'] as $r) { $rows[] = [$r['at'], $r['date_display'], 'Invoice '.$r['number'].($r['due_display'] ? ' (due '.$r['due_display'].')' : ''), $r['amount'], null]; }
                foreach ($doc['debt']['cash_allocations'] as $r) { $rows[] = [$r['at'], $r['date_display'], 'Payment against '.$r['invoice_number'], null, $r['amount']]; }
                foreach ($doc['debt']['credit_allocations'] as $r) { $rows[] = [$r['at'], $r['date_display'], 'Credit note '.$r['credit_note_number'].' against '.$r['invoice_number'], null, $r['amount']]; }
                usort($rows, fn ($a, $b) => strcmp((string) $a[0], (string) $b[0]));
            @endphp
            @forelse ($rows as $row)
                <tr><td>{{ $row[1] }}</td><td>{{ $row[2] }}</td><td class="num">{{ $row[3] }}</td><td class="num">{{ $row[4] }}</td></tr>
            @empty
                <tr><td colspan="4" class="muted">No invoices, payments or credits in this period.</td></tr>
            @endforelse
        </tbody>
    </table>

    <h2>Owed at the end, by age</h2>
    <div class="grid">
        @foreach ($doc['debt']['ageing'] as $bucket)
            <div class="box"><div class="label">{{ $bucket['label'] }}</div>{{ $bucket['amount'] }} <span class="muted">({{ $bucket['count'] }})</span></div>
        @endforeach
    </div>

    <h2>Account balance — your money to spend</h2>
    <div class="grid">
        <div class="box"><div class="label">At the start</div>{{ $doc['balance']['opening'] }}</div>
        <div class="box"><div class="label">Movements</div>{{ $doc['balance']['movements_total'] }}</div>
        <div class="box"><div class="label">At the end</div><strong>{{ $doc['balance']['closing'] }}</strong></div>
    </div>
    @if (! empty($doc['balance']['movements']))
        <table class="lines">
            <thead><tr><th>Date</th><th>Detail</th><th class="num">Amount</th></tr></thead>
            <tbody>
                @foreach ($doc['balance']['movements'] as $m)
                    <tr><td>{{ $m['date_display'] }}</td><td>{{ $m['type_label'] }}@if ($m['reference']) · {{ $m['reference'] }}@endif</td><td class="num">{{ $m['amount'] }}</td></tr>
                @endforeach
            </tbody>
        </table>
    @endif

    <table class="totals">
        <tr><td class="muted">On-account orders not yet invoiced</td><td class="num">{{ $doc['uninvoiced_holds'] }}</td></tr>
    </table>

    @include('documents.partials.footer')
@endsection
