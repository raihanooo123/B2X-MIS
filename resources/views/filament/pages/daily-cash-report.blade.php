{{-- Filament's page shell for DailyCashReportPage (05.6 §7A.6a): filters, then the day's figures from DailyCashReport. --}}
@php
    use App\Filament\Support\MoneyFormatter;
    use App\Support\DisplayTime;
    $report = $this->report();
    $th = 'px-4 py-3 text-left text-xs font-semibold uppercase tracking-wide text-gray-500 dark:text-gray-400';
    $td = 'px-4 py-3 text-sm text-gray-950 dark:text-white';
@endphp
<x-filament-panels::page>
    <x-filament::section>
        <x-filament-panels::form>
            {{ $this->form }}
        </x-filament-panels::form>
    </x-filament::section>

    @if ($report === null)
        <x-filament::section>
            <div class="flex flex-col items-center py-8 text-center">
                <x-filament::icon icon="heroicon-o-banknotes" class="mb-3 h-10 w-10 text-gray-400" />
                <p class="font-semibold text-gray-950 dark:text-white">Choose a location and a day</p>
                <p class="text-sm text-gray-500">The day's cash, by staff member, appears here.</p>
            </div>
        </x-filament::section>
    @else
        <div class="grid gap-4 sm:grid-cols-2 lg:grid-cols-4">
            @foreach ([
                ['Cash recorded', MoneyFormatter::minor($report['recorded_minor']), 'heroicon-m-banknotes', false],
                ['Voided', '−'.MoneyFormatter::minor($report['voided_minor']), 'heroicon-m-x-circle', false],
                ['Cash refunds', '−'.MoneyFormatter::minor($report['refunded_minor']), 'heroicon-m-arrow-uturn-left', false],
                ['Drawer should hold', MoneyFormatter::minor($report['net_minor']), 'heroicon-m-inbox-stack', true],
            ] as [$label, $value, $icon, $total])
                <div @class([
                    'fi-wi-stats-overview-stat relative rounded-xl bg-white p-6 shadow-sm ring-1 ring-gray-950/5 dark:bg-gray-900 dark:ring-white/10',
                    'ring-2 !ring-primary-500' => $total,
                ])>
                    <div class="flex items-center gap-x-2">
                        <x-filament::icon :icon="$icon" @class(['h-5 w-5', 'text-primary-500' => $total, 'text-gray-400' => ! $total]) />
                        <span class="text-sm font-medium text-gray-500 dark:text-gray-400">{{ $label }}</span>
                    </div>
                    <div class="fi-wi-stats-overview-stat-value mt-2 text-3xl font-semibold tabular-nums tracking-tight text-gray-950 dark:text-white">{{ $value }}</div>
                </div>
            @endforeach
        </div>

        <x-filament::section heading="By staff member" icon="heroicon-o-user-group">
            @if ($report['staff'] === [])
                <p class="text-sm text-gray-500">No cash was recorded on this day.</p>
            @else
                <div class="-mx-6 -my-6 overflow-x-auto">
                    <table class="w-full divide-y divide-gray-200 dark:divide-white/5">
                        <thead class="bg-gray-50 dark:bg-white/5">
                            <tr>
                                <th class="{{ $th }}">Staff member</th>
                                <th class="{{ $th }} text-right">Payments</th>
                                <th class="{{ $th }} text-right">Gross</th>
                                <th class="{{ $th }} text-right">Voids</th>
                                <th class="{{ $th }} text-right">Voided</th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-gray-200 dark:divide-white/5">
                            @foreach ($report['staff'] as $row)
                                <tr>
                                    <td class="{{ $td }} font-medium">{{ $row['name'] }}</td>
                                    <td class="{{ $td }} text-right tabular-nums">{{ $row['payments'] }}</td>
                                    <td class="{{ $td }} text-right tabular-nums">{{ MoneyFormatter::minor($row['gross_minor']) }}</td>
                                    <td class="{{ $td }} text-right tabular-nums">{{ $row['voids'] }}</td>
                                    <td class="{{ $td }} text-right tabular-nums">{{ MoneyFormatter::minor($row['voided_minor']) }}</td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>
            @endif
        </x-filament::section>

        <x-filament::section heading="Every entry, newest first" icon="heroicon-o-queue-list">
            @if ($report['detail'] === [])
                <p class="text-sm text-gray-500">Nothing to show.</p>
            @else
                <div class="-mx-6 -my-6 overflow-x-auto">
                    <table class="w-full divide-y divide-gray-200 dark:divide-white/5">
                        <thead class="bg-gray-50 dark:bg-white/5">
                            <tr>
                                <th class="{{ $th }}">Time (UK)</th>
                                <th class="{{ $th }}">Entry</th>
                                <th class="{{ $th }}">Order</th>
                                <th class="{{ $th }}">Customer</th>
                                <th class="{{ $th }} text-right">Amount</th>
                                <th class="{{ $th }}">Recorded by</th>
                                <th class="{{ $th }}">Voided by / why</th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-gray-200 dark:divide-white/5">
                            @foreach ($report['detail'] as $line)
                                <tr>
                                    <td class="{{ $td }} tabular-nums">{{ DisplayTime::format(\Carbon\CarbonImmutable::parse($line['at']), 'H:i') }}</td>
                                    <td class="{{ $td }}">
                                        <x-filament::badge :color="match ($line['kind']) { 'payment' => 'success', 'refund' => 'warning', default => 'danger' }">{{ ucfirst($line['kind']) }}</x-filament::badge>
                                    </td>
                                    <td class="{{ $td }} font-mono"><a class="text-primary-600 hover:underline dark:text-primary-400" href="{{ url('/warehouse/collections?order='.$line['order_id']) }}">{{ $line['order_number'] }}</a></td>
                                    <td class="{{ $td }}">{{ $line['customer'] }}</td>
                                    <td class="{{ $td }} text-right font-medium tabular-nums">{{ $line['kind'] === 'payment' ? '' : '−' }}{{ MoneyFormatter::minor($line['amount_minor']) }}</td>
                                    <td class="{{ $td }}">{{ $line['recorded_by'] ?? '—' }}</td>
                                    <td class="{{ $td }} text-gray-500">{{ $line['voided_by'] === null ? '' : $line['voided_by'].': '.$line['void_reason'] }}</td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>
            @endif
        </x-filament::section>
    @endif
</x-filament-panels::page>
