{{-- Filament's page shell for CompanyCredit (05.2 §18.3): summary, the reasoned credit form, payouts. --}}
@php
    use App\Filament\Support\MoneyFormatter;
    use App\Support\DisplayTime;
    $summary = $this->summary();
    $payouts = $this->payouts();
@endphp
<x-filament-panels::page>
    <x-filament::section heading="Summary">
        <dl class="grid gap-4 sm:grid-cols-3 lg:grid-cols-6">
            <div><dt class="text-sm text-gray-500">Credit limit</dt><dd class="text-lg font-semibold tabular-nums">{{ MoneyFormatter::minor($summary['limit_minor']) }}</dd></div>
            <div><dt class="text-sm text-gray-500">Used (invoiced)</dt><dd class="text-lg font-semibold tabular-nums">{{ MoneyFormatter::minor($summary['used_minor']) }}</dd></div>
            <div><dt class="text-sm text-gray-500">Held (orders)</dt><dd class="text-lg font-semibold tabular-nums">{{ MoneyFormatter::minor($summary['held_minor']) }}</dd></div>
            <div><dt class="text-sm text-gray-500">Available</dt><dd class="text-lg font-semibold tabular-nums">{{ MoneyFormatter::minor($summary['available_minor']) }}</dd>
                @if ($summary['over_limit_minor'] > 0)<p class="text-sm text-danger-600">Over limit by {{ MoneyFormatter::minor($summary['over_limit_minor']) }}</p>@endif
            </div>
            <div><dt class="text-sm text-gray-500">Account balance</dt><dd class="text-lg font-semibold tabular-nums">{{ MoneyFormatter::minor($summary['balance_minor']) }}</dd></div>
            <div><dt class="text-sm text-gray-500">On account</dt><dd class="text-lg font-semibold">{{ $summary['on_account']['allowed'] ? 'Open' : 'Blocked' }}</dd>
                @unless ($summary['on_account']['allowed'])<p class="text-sm text-gray-600">{{ $summary['on_account']['message'] }}</p>@endunless
            </div>
        </dl>
        @if ($summary['overdue']['count'] > 0)
            <p class="mt-4 text-sm text-danger-600">Overdue: {{ $summary['overdue']['count'] }} invoice(s), {{ MoneyFormatter::minor($summary['overdue']['amount_minor']) }}, oldest due {{ DisplayTime::format($summary['overdue']['oldest_due_at'] === null ? null : \Illuminate\Support\Carbon::parse($summary['overdue']['oldest_due_at']), 'd/m/Y') }}.</p>
        @endif
    </x-filament::section>

    <x-filament::section heading="Limit, terms and status" description="Every change is audited with your name and the reason.">
        <form wire:submit="save" class="space-y-4">
            {{ $this->form }}
            <x-filament::button type="submit">Save credit changes</x-filament::button>
        </form>
    </x-filament::section>

    <x-filament::section heading="Balance payouts">
        @if ($payouts === [])
            <p class="text-sm text-gray-500">No payouts yet.</p>
        @else
            <table class="w-full text-sm">
                <thead><tr class="text-left text-gray-500"><th class="py-1">Requested (UK)</th><th>By</th><th class="text-right">Amount</th><th>Status</th><th>Approved by</th><th class="sr-only">Actions</th></tr></thead>
                <tbody>
                    @foreach ($payouts as $payout)
                        <tr class="border-t">
                            <td class="py-1 tabular-nums">{{ DisplayTime::format($payout['requested_at'], 'd/m/Y H:i') }}</td>
                            <td>{{ $payout['requested_by'] }}</td>
                            <td class="text-right tabular-nums">{{ $payout['amount'] }}</td>
                            <td>{{ ucfirst($payout['status']) }}</td>
                            <td>{{ $payout['approved_by'] ?? '—' }}</td>
                            <td class="space-x-2 text-right">
                                @if ($payout['status'] === 'pending')
                                    @if ($payout['can_approve']){{ ($this->approvePayoutAction)(['payout' => $payout['id']]) }}@endif
                                    {{ ($this->rejectPayoutAction)(['payout' => $payout['id']]) }}
                                @endif
                            </td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        @endif
    </x-filament::section>

    <x-filament-actions::modals />
</x-filament-panels::page>
