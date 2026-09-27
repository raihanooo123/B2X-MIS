<?php

namespace App\Filament\Resources;

use App\Domain\Accounts\ApplicationDuplicates;
use App\Domain\Delivery\ZoneResolver;
use App\Domain\Identity\BusinessType;
use App\Filament\Resources\TradeApplicationResource\Pages;
use App\Filament\Support\MoneyFormatter;
use App\Models\Attachment;
use App\Models\B2bApplication;
use Filament\Infolists\Components\Section;
use Filament\Infolists\Components\TextEntry;
use Filament\Infolists\Infolist;
use Filament\Resources\Resource;
use Filament\Tables\Actions\ViewAction;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\Filter;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

/**
 * 05.2 §5.4: the trade application review queue — open applications,
 * oldest first (`b2b_applications_queue_idx`) — and each application's
 * evidence. Decisions are header actions on the detail page
 * (ViewTradeApplication), each gated by B2bApplicationPolicy.
 */
class TradeApplicationResource extends Resource
{
    protected static ?string $model = B2bApplication::class;

    protected static ?string $navigationIcon = 'heroicon-o-clipboard-document-check';

    protected static ?string $navigationGroup = 'Customers';

    protected static ?string $navigationLabel = 'Trade applications';

    protected static ?string $modelLabel = 'trade application';

    protected static ?int $navigationSort = 10;

    protected static ?string $slug = 'trade-applications';

    protected static ?string $recordTitleAttribute = 'company_name';

    private const STATUSES = [
        'submitted' => 'Submitted',
        'in_review' => 'In review',
        'info_requested' => 'Information requested',
        'approved' => 'Approved',
        'rejected' => 'Rejected',
        'withdrawn' => 'Withdrawn',
    ];

    /** @return Builder<B2bApplication> */
    public static function getEloquentQuery(): Builder
    {
        return parent::getEloquentQuery()->with(['applicant', 'reviewer', 'requestedTier', 'grantedTier', 'company']);
    }

    public static function infolist(Infolist $infolist): Infolist
    {
        return $infolist->schema([
            Section::make('Application')->schema([
                TextEntry::make('status')->badge()->formatStateUsing(fn (string $state): string => self::STATUSES[$state] ?? $state),
                TextEntry::make('submitted_at')->label('Submitted')->dateTime(),
                TextEntry::make('company_name')->label('Company name'),
                TextEntry::make('business_type')->label('Business type')
                    ->formatStateUsing(fn (?string $state): string => BusinessType::tryFrom((string) $state)?->label() ?? '—'),
                TextEntry::make('registration_number')->label('Companies House number')->placeholder('Not given'),
                TextEntry::make('vat_number')->label('VAT number')->placeholder('Not given'),
                TextEntry::make('estimated_monthly_spend_minor')->label('Estimated monthly spend')
                    ->formatStateUsing(fn (?int $state): string => MoneyFormatter::minor($state) ?? '—')->placeholder('Not given'),
                TextEntry::make('requestedTier.name')->label('Requested tier')->placeholder('None'),
            ])->columns(2),
            Section::make('Contact')->schema([
                TextEntry::make('contact_name')->label('Name'),
                TextEntry::make('contact_email')->label('Email'),
                TextEntry::make('contact_phone')->label('Phone')->placeholder('Not given'),
                TextEntry::make('applicant_account')->label('Applicant account')
                    ->state(fn (B2bApplication $record): string => self::applicantSummary($record)),
            ])->columns(2),
            Section::make('Trading address')->schema([
                TextEntry::make('address_lines')->hiddenLabel()
                    ->state(fn (B2bApplication $record): array => self::addressLines($record))->listWithLineBreaks(),
                TextEntry::make('delivery_zone')->label('Delivery zone')
                    ->state(fn (B2bApplication $record): string => self::deliveryZone($record)),
            ])->columns(2),
            Section::make('Duplicate checks')->description('For the reviewer only. A match is a prompt to check, never a reason to reject on its own (05.2 §5.2).')->schema([
                TextEntry::make('duplicate_flags')->hiddenLabel()
                    ->state(fn (B2bApplication $record): array => app(ApplicationDuplicates::class)->flags($record))
                    ->listWithLineBreaks()->placeholder('No possible duplicates found.'),
            ]),
            Section::make('Documents')->schema([
                TextEntry::make('documents')->hiddenLabel()
                    ->state(fn (B2bApplication $record): array => self::documents($record))
                    ->listWithLineBreaks()->placeholder('No documents uploaded.'),
            ]),
            Section::make('Review')->schema([
                TextEntry::make('reviewer.email')->label('Reviewer')->placeholder('Not picked up'),
                TextEntry::make('reviewed_at')->label('Decided')->dateTime()->placeholder('—'),
                TextEntry::make('info_request')->label('Information requested')->placeholder('—')->columnSpanFull(),
                TextEntry::make('review_note')->label('Internal reason')->placeholder('—')->columnSpanFull(),
                TextEntry::make('grantedTier.name')->label('Granted tier')->placeholder('—'),
                TextEntry::make('company.account_code')->label('Account code')->placeholder('—'),
            ])->columns(2),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table->columns([
            TextColumn::make('submitted_at')->label('Submitted')->dateTime()->sortable(),
            TextColumn::make('company_name')->label('Company')->searchable()->sortable(),
            TextColumn::make('contact_name')->label('Contact')->searchable(),
            TextColumn::make('contact_email')->label('Email')->searchable(),
            TextColumn::make('business_type')->label('Business type')
                ->formatStateUsing(fn (?string $state): string => BusinessType::tryFrom((string) $state)?->label() ?? '—'),
            TextColumn::make('status')->badge()->formatStateUsing(fn (string $state): string => self::STATUSES[$state] ?? $state),
            TextColumn::make('reviewer.email')->label('Reviewer')->placeholder('—'),
        ])->filters([
            Filter::make('open')->label('Open applications only')->default()
                ->query(fn (Builder $query): Builder => $query->whereIn('status', B2bApplication::OPEN_STATUSES)),
            SelectFilter::make('status')->options(self::STATUSES),
        ])->actions([ViewAction::make()])->defaultSort('submitted_at', 'asc');
    }

    private static function applicantSummary(B2bApplication $application): string
    {
        $applicant = $application->applicant;
        if ($applicant === null) {
            return 'None — entered by staff; review from here is not supported yet.';
        }

        $verified = $applicant->email_verified_at === null ? 'email not confirmed' : 'email confirmed';

        return "{$applicant->email} — {$applicant->status}, {$verified}";
    }

    /** @return list<string> */
    private static function addressLines(B2bApplication $application): array
    {
        $lines = [];
        foreach (['line1', 'line2', 'city', 'county', 'postcode', 'country_code'] as $key) {
            $value = $application->address[$key] ?? null;
            if (is_string($value) && trim($value) !== '') {
                $lines[] = trim($value);
            }
        }

        return $lines;
    }

    private static function deliveryZone(B2bApplication $application): string
    {
        $postcode = $application->address['postcode'] ?? null;
        $country = $application->address['country_code'] ?? 'GB';
        if (! is_string($postcode) || ! is_string($country)) {
            return '—';
        }

        $resolution = app(ZoneResolver::class)->resolve($postcode, $country);
        if ($resolution->zone === null) {
            return 'No delivery zone covers this address.';
        }

        return $resolution->zone->name.($resolution->recognised ? '' : ' (postcode not recognised — check it)');
    }

    /** @return list<string> */
    private static function documents(B2bApplication $application): array
    {
        return array_values(Attachment::query()
            ->where('attachable_type', 'b2b_application')
            ->where('attachable_id', $application->id)
            ->orderBy('id')
            ->get(['original_name', 'mime_type'])
            ->map(fn (Attachment $attachment): string => ($attachment->original_name ?? 'Unnamed file')." ({$attachment->mime_type})")
            ->all());
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListTradeApplications::route('/'),
            'view' => Pages\ViewTradeApplication::route('/{record}'),
        ];
    }
}
