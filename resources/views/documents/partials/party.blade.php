{{-- A name and address block: seller, customer or delivery. Wraps, never clips. --}}
<div>
    <div class="label">{{ $label }}</div>
    @if (! empty($party['legal_name'] ?? $party['name'] ?? null))
        <div><strong>{{ $party['legal_name'] ?? $party['name'] }}</strong></div>
    @endif
    @if (! empty($party['contact_name'] ?? null))
        <div>{{ $party['contact_name'] }}</div>
    @endif
    @foreach (($party['address_lines'] ?? []) as $line)
        <div>{{ $line }}</div>
    @endforeach
    @if (! empty($party['account_code'] ?? null))
        <div class="muted">Account {{ $party['account_code'] }}</div>
    @endif
    @if (! empty($party['vat_number'] ?? null))
        <div class="muted">VAT {{ $party['vat_number'] }}</div>
    @endif
    @if (! empty($party['company_number'] ?? null))
        <div class="muted">Company number {{ $party['company_number'] }}</div>
    @endif
</div>
