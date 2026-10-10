{{-- Filament's page shell for CreditExceptionsPage (05.2 §18.3): the credit-shortfall queue, then overdue accounts. --}}
@php
    use App\Filament\Resources\CompanyResource;
    $overdue = $this->overdueAccounts();
@endphp
<x-filament-panels::page>
    {{ $this->table }}

    <x-filament::section heading="Overdue accounts" description="Trade accounts with an invoice unpaid past its due date. On account is blocked for them; accounts over the automatic threshold are suspended overnight.">
        @if ($overdue === [])
            <p class="text-sm text-gray-500">No account has overdue debt.</p>
        @else
            <table class="w-full text-sm">
                <thead><tr class="text-left text-gray-500"><th class="py-1">Account</th><th>Status</th><th class="text-right">Overdue</th><th class="text-right">Oldest due</th><th class="sr-only">Open</th></tr></thead>
                <tbody>
                    @foreach ($overdue as $row)
                        <tr class="border-t">
                            <td class="py-1">{{ $row['name'] }}</td>
                            <td>{{ ucfirst($row['status']) }}</td>
                            <td class="text-right tabular-nums">{{ $row['amount'] }}</td>
                            <td class="text-right tabular-nums">{{ $row['oldest'] }}</td>
                            <td class="text-right"><a class="text-primary-600 underline" href="{{ CompanyResource::getUrl('credit', ['record' => $row['id']]) }}">Credit page</a></td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        @endif
    </x-filament::section>
</x-filament-panels::page>
