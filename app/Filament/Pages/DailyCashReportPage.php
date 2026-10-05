<?php

namespace App\Filament\Pages;

use App\Domain\Collection\CollectionSlots;
use App\Domain\Collection\DailyCashReport;
use App\Models\CollectionBooking;
use App\Models\Location;
use Carbon\CarbonImmutable;
use Filament\Actions\Action;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\Select;
use Filament\Forms\Concerns\InteractsWithForms;
use Filament\Forms\Contracts\HasForms;
use Filament\Forms\Form;
use Filament\Pages\Page;
use Illuminate\Support\Facades\Gate;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * @phpstan-import-type CashReport from DailyCashReport
 *
 * 05.6 §7A.6a — Collections → Daily cash report: the cash taken at a
 * location on one UK calendar day, per staff member, the day's net and
 * every entry, with a CSV of the detail for the cash-up sheet. For staff
 * with `accounts` or the `dispatch` permission. Till counting and
 * variances are POS (ROADMAP §25, parked).
 *
 * @property Form $form
 */
class DailyCashReportPage extends Page implements HasForms
{
    use InteractsWithForms;

    protected static ?string $navigationIcon = 'heroicon-o-banknotes';

    protected static ?string $navigationGroup = 'Collections';

    protected static ?string $navigationLabel = 'Daily cash report';

    protected static ?string $title = 'Daily cash report';

    protected static ?string $slug = 'collections/daily-cash-report';

    protected static string $view = 'filament.pages.daily-cash-report';

    /** @var array<string, mixed>|null */
    public ?array $filters = [];

    public static function canAccess(): bool
    {
        return Gate::allows('viewCashReport', CollectionBooking::class);
    }

    public function mount(): void
    {
        $this->form->fill([
            'location_id' => Location::query()->where('is_sellable', true)->orderByDesc('is_default')->orderBy('id')->value('id'),
            'day' => CarbonImmutable::now(CollectionSlots::ZONE)->toDateString(),
        ]);
    }

    public function form(Form $form): Form
    {
        return $form
            ->statePath('filters')
            ->columns(2)
            ->schema([
                Select::make('location_id')->label('Location')->required()->live()
                    ->options(Location::query()->where('is_sellable', true)->orderBy('name')->pluck('name', 'id')),
                DatePicker::make('day')->label('Day (UK)')->required()->live()->native(false)->maxDate(now(CollectionSlots::ZONE)->endOfDay()),
            ]);
    }

    /**
     * @return CashReport|null
     */
    public function report(): ?array
    {
        $location = $this->filters['location_id'] ?? null;
        $day = $this->filters['day'] ?? null;
        if (! is_numeric($location) || ! is_string($day) || $day === '') {
            return null;
        }

        return (new DailyCashReport)->build((int) $location, CarbonImmutable::parse($day, CollectionSlots::ZONE));
    }

    protected function getHeaderActions(): array
    {
        return [
            Action::make('csv')
                ->label('Download CSV')
                ->icon('heroicon-o-arrow-down-tray')
                ->action(fn (): ?StreamedResponse => $this->csv()),
        ];
    }

    private function csv(): ?StreamedResponse
    {
        $report = $this->report();
        if ($report === null) {
            return null;
        }

        return response()->streamDownload(function () use ($report): void {
            $out = fopen('php://output', 'w');
            if ($out === false) {
                return;
            }
            foreach (DailyCashReport::csvRows($report) as $row) {
                fputcsv($out, $row);
            }
            fclose($out);
        }, "cash-report-{$report['day']}.csv", ['Content-Type' => 'text/csv; charset=UTF-8']);
    }
}
