<?php

namespace App\Filament\Resources;

use App\Domain\Accounts\ApplicationDuplicates;
use App\Domain\Accounts\ApplicationSettings;
use App\Domain\Accounts\BusinessVerification;
use App\Domain\Accounts\LegalForm;
use App\Domain\Accounts\RejectionCategory;
use App\Domain\Accounts\VatCheckAuthority;
use App\Domain\Accounts\VerificationFailureReason;
use App\Domain\Accounts\VerificationWarning;
use App\Domain\Delivery\ZoneResolver;
use App\Domain\Identity\BusinessType;
use App\Filament\Resources\TradeApplicationResource\Pages;
use App\Filament\Support\MoneyFormatter;
use App\Filament\Support\SentenceCaseLabels;
use App\Models\Attachment;
use App\Models\B2bApplication;
use App\Models\CompaniesHouseCheck;
use App\Models\User;
use App\Models\VatNumberCheck;
use App\Support\DisplayTime;
use Carbon\CarbonInterface;
use Filament\Infolists\Components\Group;
use Filament\Infolists\Components\Section;
use Filament\Infolists\Components\TextEntry;
use Filament\Infolists\Infolist;
use Filament\Resources\Resource;
use Filament\Tables\Actions\ViewAction;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

/**
 * 05.2 §5.4: the trade application review queue — open applications,
 * oldest first (`b2b_applications_queue_idx`, the list's default tab) —
 * and each application's evidence. Decisions are header actions on the detail page
 * (ViewTradeApplication), each gated by B2bApplicationPolicy.
 */
class TradeApplicationResource extends Resource
{
    use SentenceCaseLabels;

    protected static ?string $model = B2bApplication::class;

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
        return parent::getEloquentQuery()->with(['applicant', 'reviewer', 'requestedTier', 'grantedTier', 'company', 'termsAcceptance.termsVersion']);
    }

    public static function infolist(Infolist $infolist): Infolist
    {
        return $infolist
            ->columns(['default' => 1, 'lg' => 3])
            ->schema([
                Group::make()
                    ->columnSpan(['lg' => 2])
                    ->schema([
                        Section::make('Business')
                            ->icon('heroicon-o-building-office')
                            ->schema([
                                TextEntry::make('company_name')
                                    ->label('Company name')
                                    ->size(TextEntry\TextEntrySize::Large)
                                    ->weight('semibold')
                                    ->columnSpanFull(),
                                TextEntry::make('legal_form')->label('Legal form')
                                    ->formatStateUsing(fn (?string $state): string => LegalForm::tryFrom((string) $state)?->label() ?? '—')
                                    ->placeholder('Not recorded (applied before legal form was asked)'),
                                TextEntry::make('business_type')->label('Business type')
                                    ->formatStateUsing(fn (?string $state): string => BusinessType::tryFrom((string) $state)?->label() ?? '—'),
                                TextEntry::make('registration_number')->label('Companies House number')->placeholder('Not given')->copyable(),
                                TextEntry::make('vat_number')->label('VAT number')->placeholder('Not given')->copyable(),
                            ])
                            ->columns(2),

                        Section::make('Contact')
                            ->icon('heroicon-o-user')
                            ->schema([
                                TextEntry::make('contact_name')->label('Name'),
                                TextEntry::make('contact_email')->label('Email')->copyable(),
                                TextEntry::make('contact_phone')->label('Phone')->placeholder('Not given'),
                                TextEntry::make('applicant_account')->label('Applicant account')
                                    ->state(fn (B2bApplication $record): string => self::applicantSummary($record)),
                                TextEntry::make('terms_accepted')->label('Terms of trade accepted')->columnSpanFull()
                                    ->state(fn (B2bApplication $record): string => self::termsSummary($record)),
                            ])
                            ->columns(2),

                        Section::make('Trading address')
                            ->icon('heroicon-o-map-pin')
                            ->schema([
                                TextEntry::make('address_lines')->label('Address')
                                    ->state(fn (B2bApplication $record): array => self::addressLines($record))->listWithLineBreaks(),
                                TextEntry::make('delivery_zone')->label('Delivery zone')
                                    ->state(fn (B2bApplication $record): string => self::deliveryZone($record)),
                            ])
                            ->columns(2),

                        Section::make('Evidence and checks')
                            ->icon('heroicon-o-shield-check')
                            ->description(fn (): string => 'The VAT number is checked with HMRC (or the EU VIES service), and the company number with Companies House. Evidence older than '.app(ApplicationSettings::class)->verificationMaxAgeDays().' days is out of date: re-run the checks before relying on it.')
                            ->schema([
                                TextEntry::make('verification_outcome')->label('For approval')->columnSpanFull()
                                    ->state(fn (B2bApplication $record): array => self::assessmentLines($record))->listWithLineBreaks()
                                    ->weight('medium'),
                                TextEntry::make('vat_evidence')->label('VAT number')
                                    ->state(fn (B2bApplication $record): array => self::vatLines($record))->listWithLineBreaks(),
                                TextEntry::make('companies_house_evidence')->label('Companies House')
                                    ->state(fn (B2bApplication $record): array => self::companyLines($record))->listWithLineBreaks(),
                                TextEntry::make('verification_history')->label('History')->columnSpanFull()
                                    ->state(fn (B2bApplication $record): array => self::historyLines($record))->listWithLineBreaks()
                                    ->placeholder('No checks have run yet.')
                                    ->color('gray'),
                            ])
                            ->columns(2),

                        Section::make('Possible duplicates')
                            ->icon('heroicon-o-document-duplicate')
                            ->description('For the reviewer only. A match is a reason to look more closely, never a reason to reject on its own.')
                            ->schema([
                                TextEntry::make('duplicate_flags')->hiddenLabel()
                                    ->state(fn (B2bApplication $record): array => app(ApplicationDuplicates::class)->flags($record))
                                    ->listWithLineBreaks()->placeholder('No possible duplicates found.'),
                            ]),

                        Section::make('Documents')
                            ->icon('heroicon-o-paper-clip')
                            ->schema([
                                TextEntry::make('documents')->hiddenLabel()
                                    ->state(fn (B2bApplication $record): array => self::documents($record))
                                    ->listWithLineBreaks()->placeholder('No documents uploaded.'),
                            ]),
                    ]),

                Group::make()
                    ->columnSpan(['lg' => 1])
                    ->schema([
                        Section::make('Status')
                            ->schema([
                                TextEntry::make('status')->badge()
                                    ->color(fn (string $state): string => self::statusColor($state))
                                    ->formatStateUsing(fn (string $state): string => self::STATUSES[$state] ?? $state),
                                TextEntry::make('checks')->label('Checks')->badge()
                                    ->state(fn (B2bApplication $record): string => app(BusinessVerification::class)->assess($record)->badge())
                                    ->color(fn (string $state): string => self::checksColor($state)),
                                TextEntry::make('submitted_at')->label('Submitted')->dateTime()
                                    ->helperText(fn (B2bApplication $record): string => $record->submitted_at->diffForHumans()),
                            ]),

                        Section::make('What they asked for')
                            ->schema([
                                TextEntry::make('requestedTier.name')->label('Requested tier')->placeholder('None'),
                                TextEntry::make('estimated_monthly_spend_minor')->label('Estimated monthly spend')
                                    ->formatStateUsing(fn (?int $state): string => MoneyFormatter::minor($state) ?? '—')->placeholder('Not given'),
                            ]),

                        Section::make('Review')
                            ->schema([
                                TextEntry::make('reviewer.email')->label('Reviewer')->placeholder('Not picked up'),
                                TextEntry::make('reviewed_at')->label('Decided')->dateTime()->placeholder('—'),
                                TextEntry::make('info_request')->label('Information requested')->placeholder('—'),
                                TextEntry::make('review_note')->label('Internal reason')->placeholder('—'),
                                TextEntry::make('rejection_category')->label('Rejection category')->placeholder('—')
                                    ->formatStateUsing(fn (?string $state): string => RejectionCategory::tryFrom((string) $state)?->label() ?? '—'),
                                TextEntry::make('reapply')->label('May apply again')
                                    ->state(fn (B2bApplication $record): ?string => self::reapplySummary($record))->placeholder('—'),
                                TextEntry::make('applicant_message')->label('Message to the applicant')->placeholder('—'),
                                TextEntry::make('grantedTier.name')->label('Granted tier')->placeholder('—'),
                                TextEntry::make('company.account_code')->label('Account code')->placeholder('—')
                                    ->url(fn (B2bApplication $record): ?string => $record->company_id === null || ! CompanyResource::canViewAny() ? null : CompanyResource::getUrl('view', ['record' => $record->company_id])),
                            ]),
                    ]),
            ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('company_name')->label('Company')
                    ->weight('medium')
                    ->description(fn (B2bApplication $record): string => $record->contact_name)
                    ->searchable(['company_name', 'contact_name', 'contact_email'])
                    ->sortable(),
                TextColumn::make('submitted_at')->label('Submitted')->dateTime()->sortable()
                    ->description(fn (B2bApplication $record): string => $record->submitted_at->diffForHumans()),
                TextColumn::make('status')->badge()->color(fn (string $state): string => self::statusColor($state))
                    ->formatStateUsing(fn (string $state): string => self::STATUSES[$state] ?? $state),
                TextColumn::make('checks')->label('Checks')->badge()
                    ->state(fn (B2bApplication $record): string => app(BusinessVerification::class)->assess($record)->badge())
                    ->color(fn (string $state): string => self::checksColor($state)),
                TextColumn::make('reviewer.email')->label('Reviewer')->placeholder('Not picked up')->toggleable(),
                TextColumn::make('contact_email')->label('Email')->toggleable(isToggledHiddenByDefault: true),
                TextColumn::make('business_type')->label('Business type')
                    ->formatStateUsing(fn (?string $state): string => BusinessType::tryFrom((string) $state)?->label() ?? '—')
                    ->toggleable(isToggledHiddenByDefault: true),
                TextColumn::make('requestedTier.name')->label('Requested tier')->placeholder('None')
                    ->toggleable(isToggledHiddenByDefault: true),
            ])
            ->filters([
                SelectFilter::make('business_type')->label('Business type')->options(fn (): array => collect(BusinessType::cases())->mapWithKeys(fn (BusinessType $type): array => [$type->value => $type->label()])->all()),
            ])
            ->actions([ViewAction::make()->iconButton()->tooltip('Review')])
            ->striped()
            ->defaultSort('submitted_at', 'asc')
            ->emptyStateIcon('heroicon-o-check-circle')
            ->emptyStateHeading('No applications here')
            ->emptyStateDescription('New trade applications appear under Open as they are submitted.');
    }

    /** @return array<string, string> */
    public static function statusOptions(): array
    {
        return self::STATUSES;
    }

    private static function checksColor(string $state): string
    {
        return match ($state) {
            'Verified' => 'success',
            'Needs attention' => 'danger',
            'Unchecked' => 'warning',
            default => 'gray',
        };
    }

    /** 05.2 §17 badge colours: rejected red, approved green, in progress amber, submitted grey. */
    public static function statusColor(string $status): string
    {
        return match ($status) {
            'rejected' => 'danger',
            'approved' => 'success',
            'in_review', 'info_requested' => 'warning',
            default => 'gray',
        };
    }

    /** @return list<string> */
    private static function assessmentLines(B2bApplication $application): array
    {
        $assessment = app(BusinessVerification::class)->assess($application);
        if (! $assessment->vatApplies && ! $assessment->companiesHouseApplies) {
            return ['No VAT or Companies House number given, so there is nothing to check.'];
        }
        if ($assessment->refusal !== null) {
            return ['Cannot be approved: '.$assessment->refusal];
        }
        if ($assessment->warnings === []) {
            return ['Verified — no warnings.'];
        }

        return array_map(fn (VerificationWarning $w): string => 'Needs acknowledgement: '.$w->label(), $assessment->warnings);
    }

    /** @return list<string> */
    private static function vatLines(B2bApplication $application): array
    {
        if ($application->vat_number === null) {
            return ['No VAT number given.'];
        }
        $check = app(BusinessVerification::class)->latestVat($application);
        if ($check === null) {
            return ["{$application->vat_number} — not checked yet."];
        }

        $lines = [VatCheckAuthority::from($check->authority)->label()." — {$check->vat_number}: ".self::outcomeLabel($check->outcome, $check->failure_reason)];
        if ($check->registered_name !== null) {
            $lines[] = "Registered name: {$check->registered_name} (applied as {$application->company_name})";
        }
        if ($check->registered_address !== null) {
            $lines[] = 'Registered address: '.self::flatten($check->registered_address);
        }
        if ($check->consultation_number !== null) {
            $lines[] = "Consultation number: {$check->consultation_number}";
        }
        $lines[] = self::age($check->checked_at);

        return $lines;
    }

    /** @return list<string> */
    private static function companyLines(B2bApplication $application): array
    {
        if ($application->registration_number === null) {
            return ['No Companies House number given.'];
        }
        $check = app(BusinessVerification::class)->latestCompany($application);
        if ($check === null) {
            return ["{$application->registration_number} — not checked yet."];
        }

        $lines = ["{$check->company_number}: ".self::outcomeLabel($check->outcome, $check->failure_reason)];
        if ($check->registered_name !== null) {
            $lines[] = "Registered name: {$check->registered_name} (applied as {$application->company_name})";
            $lines[] = 'Status: '.$check->company_status.($check->company_type === null ? '' : ", type {$check->company_type}");
        }
        if ($check->registered_office !== null) {
            $lines[] = 'Registered office: '.self::flatten($check->registered_office);
        }
        if ($check->incorporated_on !== null) {
            $lines[] = 'Incorporated '.$check->incorporated_on->format('j M Y');
        }
        $lines[] = self::age($check->checked_at);

        return $lines;
    }

    /** @return list<string> every attempt, newest first */
    private static function historyLines(B2bApplication $application): array
    {
        $rows = [];
        foreach (VatNumberCheck::query()->where('b2b_application_id', $application->id)->get() as $check) {
            $rows[] = [$check->checked_at, $check->id, 'VAT ('.VatCheckAuthority::from($check->authority)->label().')', $check->outcome, $check->failure_reason, $check->requested_by_user_id];
        }
        foreach (CompaniesHouseCheck::query()->where('b2b_application_id', $application->id)->get() as $check) {
            $rows[] = [$check->checked_at, $check->id, 'Companies House', $check->outcome, $check->failure_reason, $check->requested_by_user_id];
        }
        usort($rows, fn (array $a, array $b): int => [$b[0], $b[1]] <=> [$a[0], $a[1]]);

        $emails = User::query()->whereKey(array_filter(array_column($rows, 5)))->pluck('email', 'id');

        return array_map(fn (array $row): string => DisplayTime::format($row[0]).' — '.$row[2].': '
            .self::outcomeLabel($row[3], $row[4])
            .($row[5] === null ? ' (automatic)' : ' (re-run by '.($emails[$row[5]] ?? 'a reviewer').')'), $rows);
    }

    private static function outcomeLabel(string $outcome, ?string $failure): string
    {
        return match ($outcome) {
            'valid' => 'registered',
            'found' => 'found',
            'not_found' => 'not found',
            default => 'unchecked — '.(VerificationFailureReason::tryFrom((string) $failure)?->label() ?? 'no answer'),
        };
    }

    private static function age(CarbonInterface $checkedAt): string
    {
        $maxAge = app(ApplicationSettings::class)->verificationMaxAgeDays();
        $stale = $checkedAt->lt(now()->subDays($maxAge));

        return 'Checked '.DisplayTime::format($checkedAt).' ('.$checkedAt->diffForHumans().')'
            .($stale ? " — STALE: older than {$maxAge} days, re-run before relying on it" : '');
    }

    /** @param array<string, mixed> $parts */
    private static function flatten(array $parts): string
    {
        return implode(', ', array_filter(array_map(fn ($part) => is_scalar($part) ? trim((string) $part) : '', $parts)));
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

    /** 02 §25.1: "not recorded" for applications filed before terms were versioned. */
    private static function termsSummary(B2bApplication $application): string
    {
        $acceptance = $application->termsAcceptance;
        if ($acceptance === null) {
            return 'Accepted before terms were versioned — not recorded.';
        }

        $version = $acceptance->termsVersion->version ?? '?';
        $at = DisplayTime::format($acceptance->accepted_at);

        return "Version {$version}, {$at}".($acceptance->ip === null ? '' : " from {$acceptance->ip}");
    }

    private static function reapplySummary(B2bApplication $application): ?string
    {
        if ($application->status !== 'rejected') {
            return null;
        }

        return $application->reapply_after === null
            ? 'Straight away (remediable)'
            : 'From '.DisplayTime::format($application->reapply_after, DisplayTime::DATE);
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
