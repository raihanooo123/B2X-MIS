{{-- Filament's page shell for DailyCashReportPage (05.6 §7A.6a): filters, then the day's figures from DailyCashReport. --}}
@php
    use App\Filament\Support\MoneyFormatter;
    use App\Support\DisplayTime;
    $report = $this->report();
@endphp
<x-filament-panels::page>
    <x-filament-panels::form>
        {{ $this->form }}
    </x-filament-panels::form>

    @if ($report === null)
        <p class="text-sm text-gray-500">Choose a location and a day.</p>
    @else
        <x-filament::section heading="Day total">
            <dl class="grid gap-4 sm:grid-cols-4">
                <div><dt class="text-sm text-gray-500">Cash recorded</dt><dd class="text-lg font-semibold tabular-nums">{{ MoneyFormatter::minor($report['recorded_minor']) }}</dd></div>
                <div><dt class="text-sm text-gray-500">Voided</dt><dd class="text-lg font-semibold tabular-nums">−{{ MoneyFormatter::minor($report['voided_minor']) }}</dd></div>
                <div><dt class="text-sm text-gray-500">Cash refunds</dt><dd class="text-lg font-semibold tabular-nums">−{{ MoneyFormatter::minor($report['refunded_minor']) }}</dd></div>
                <div><dt class="text-sm text-gray-500">Drawer should hold</dt><dd class="text-lg font-bold tabular-nums">{{ MoneyFormatter::minor($report['net_minor']) }}</dd></div>
            </dl>
        </x-filament::section>

        <x-filament::section heading="By staff member">
            @if ($report['staff'] === [])
                <p class="text-sm text-gray-500">No cash was recorded on this day.</p>
            @else
                <table class="w-full text-sm">
                    <thead><tr class="text-left text-gray-500"><th class="py-1">Staff member</th><th class="text-right">Payments</th><th class="text-right">Gross</th><th class="text-right">Voids</th><th class="text-right">Voided</th></tr></thead>
                    <tbody>
                        @foreach ($report['staff'] as $row)
                            <tr class="border-t"><td class="py-1">{{ $row['name'] }}</td><td class="text-right tabular-nums">{{ $row['payments'] }}</td><td class="text-right tabular-nums">{{ MoneyFormatter::minor($row['gross_minor']) }}</td><td class="text-right tabular-nums">{{ $row['voids'] }}</td><td class="text-right tabular-nums">{{ MoneyFormatter::minor($row['voided_minor']) }}</td></tr>
                        @endforeach
                    </tbody>
                </table>
            @endif
        </x-filament::section>

        <x-filament::section heading="Every entry, newest first">
            @if ($report['detail'] === [])
                <p class="text-sm text-gray-500">Nothing to show.</p>
            @else
                <table class="w-full text-sm">
                    <thead><tr class="text-left text-gray-500"><th class="py-1">Time (UK)</th><th>Entry</th><th>Order</th><th>Customer</th><th class="text-right">Amount</th><th>Recorded by</th><th>Voided by / why</th></tr></thead>
                    <tbody>
                        @foreach ($report['detail'] as $line)
                            <tr class="border-t">
                                <td class="py-1 tabular-nums">{{ DisplayTime::format(\Carbon\CarbonImmutable::parse($line['at']), 'H:i') }}</td>
                                <td>{{ ucfirst($line['kind']) }}</td>
                                <td><a class="underline" href="{{ url('/warehouse/collections?order='.$line['order_id']) }}">{{ $line['order_number'] }}</a></td>
                                <td>{{ $line['customer'] }}</td>
                                <td class="text-right tabular-nums">{{ $line['kind'] === 'payment' ? '' : '−' }}{{ MoneyFormatter::minor($line['amount_minor']) }}</td>
                                <td>{{ $line['recorded_by'] ?? '—' }}</td>
                                <td>{{ $line['voided_by'] === null ? '' : $line['voided_by'].': '.$line['void_reason'] }}</td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            @endif
        </x-filament::section>
    @endif
</x-filament-panels::page>
