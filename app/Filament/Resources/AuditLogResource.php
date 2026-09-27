<?php

namespace App\Filament\Resources;

use App\Filament\Resources\AuditLogResource\Pages;
use App\Models\AuditLog;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\TextInput;
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
use Illuminate\Support\Carbon;

class AuditLogResource extends Resource
{
    protected static ?string $model = AuditLog::class;

    protected static ?string $navigationIcon = 'heroicon-o-shield-check';

    protected static ?string $navigationGroup = 'Settings';

    protected static ?string $navigationLabel = 'Audit log';

    protected static ?int $navigationSort = 90;

    protected static ?string $recordTitleAttribute = 'id';

    private const FAMILIES = [
        'auth' => 'Authentication',
        'permission' => 'Permissions',
        'credit_limit' => 'Credit limit',
        'price' => 'Prices',
        'price_override' => 'Price overrides',
        'discount_authority' => 'Discount authority',
        'fee_waiver' => 'Fee waivers',
        'stock_adjustment' => 'Stock adjustments',
        'configuration' => 'Configuration',
        'rep_session' => 'Rep sessions',
        'rma_disposition' => 'RMA dispositions',
    ];

    public static function canCreate(): bool
    {
        return false;
    }

    /** @return Builder<AuditLog> */
    public static function getEloquentQuery(): Builder
    {
        return parent::getEloquentQuery()->with(['actor:id,email', 'company:id,name']);
    }

    public static function infolist(Infolist $infolist): Infolist
    {
        return $infolist->schema([
            Section::make('Event')->schema([
                TextEntry::make('occurred_at')->label('When')->dateTime(),
                TextEntry::make('event_family')->label('Family'),
                TextEntry::make('action'),
                TextEntry::make('actor_type')->label('Actor type'),
                TextEntry::make('actor.email')->label('Actor')->placeholder('—'),
                TextEntry::make('company.name')->label('Company')->placeholder('—'),
                TextEntry::make('acting_for_company_id')->label('Acting for company ID')->placeholder('—'),
                TextEntry::make('subject_type')->label('Subject type')->placeholder('—'),
                TextEntry::make('subject_id')->label('Subject ID')->placeholder('—'),
                TextEntry::make('ip')->label('IP address')->placeholder('—'),
                TextEntry::make('user_agent')->label('User agent')->placeholder('—')->columnSpanFull(),
                TextEntry::make('reason')->placeholder('—')->columnSpanFull(),
            ])->columns(3),
            Section::make('Changes')->schema([
                TextEntry::make('before')->state(fn (AuditLog $record): ?string => self::json($record->before))
                    ->placeholder('—')->columnSpanFull(),
                TextEntry::make('after')->state(fn (AuditLog $record): ?string => self::json($record->after))
                    ->placeholder('—')->columnSpanFull(),
            ]),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table->columns([
            TextColumn::make('occurred_at')->label('When')->dateTime()->sortable(),
            TextColumn::make('event_family')->label('Family')->badge(),
            TextColumn::make('action')->searchable(),
            TextColumn::make('actor_display')->label('Actor')
                ->state(fn (AuditLog $record): string => $record->actor->email
                    ?? ($record->actor_type === 'user' ? 'User #'.$record->actor_user_id : ucfirst($record->actor_type))),
            TextColumn::make('company_display')->label('Company')
                ->state(fn (AuditLog $record): ?string => $record->company->name
                    ?? ($record->company_id === null ? null : 'Company #'.$record->company_id))
                ->placeholder('—'),
            TextColumn::make('subject_display')->label('Subject')
                ->state(fn (AuditLog $record): ?string => $record->subject_type === null
                    ? null : $record->subject_type.' #'.$record->subject_id)
                ->placeholder('—'),
        ])->filters([
            SelectFilter::make('event_family')->label('Family')->options(self::FAMILIES),
            Filter::make('action')->form([TextInput::make('value')->label('Action')])
                ->query(fn (Builder $query, array $data): Builder => $query
                    ->when($data['value'] ?? null, fn (Builder $query, string $value) => $query->where('action', $value))),
            SelectFilter::make('actor_type')->options([
                'user' => 'User', 'system' => 'System', 'anonymous' => 'Anonymous',
            ]),
            SelectFilter::make('actor_user_id')->label('Actor')->relationship('actor', 'email')->searchable(),
            SelectFilter::make('company_id')->label('Company')->relationship('company', 'name')->searchable(),
            Filter::make('subject')->form([
                TextInput::make('type')->label('Subject type'),
                TextInput::make('id')->label('Subject ID')->numeric()->minValue(1),
            ])->query(fn (Builder $query, array $data): Builder => $query
                ->when($data['type'] ?? null, fn (Builder $query, string $type) => $query->where('subject_type', $type))
                ->when($data['id'] ?? null, fn (Builder $query, int|string $id) => $query->where('subject_id', $id))),
            Filter::make('occurred_at')->label('Date')->form([
                DatePicker::make('from')->label('From'),
                DatePicker::make('to')->label('To'),
            ])->query(fn (Builder $query, array $data): Builder => $query
                ->when($data['from'] ?? null, fn (Builder $query, string $from) => $query
                    ->where('occurred_at', '>=', Carbon::parse($from, 'UTC')->startOfDay()))
                ->when($data['to'] ?? null, fn (Builder $query, string $to) => $query
                    ->where('occurred_at', '<', Carbon::parse($to, 'UTC')->addDay()->startOfDay()))),
        ])->actions([ViewAction::make()])->defaultSort('occurred_at', 'desc');
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListAuditLogs::route('/'),
            'view' => Pages\ViewAuditLog::route('/{record}'),
        ];
    }

    /** @param array<string, mixed>|null $value */
    private static function json(?array $value): ?string
    {
        return $value === null ? null : json_encode($value, JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR);
    }
}
