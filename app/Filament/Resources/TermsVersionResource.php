<?php

namespace App\Filament\Resources;

use App\Domain\Accounts\TermsKind;
use App\Filament\Resources\TermsVersionResource\Pages;
use App\Models\TermsVersion;
use Filament\Forms\Components\Checkbox;
use Filament\Forms\Components\DateTimePicker;
use Filament\Forms\Components\MarkdownEditor;
use Filament\Forms\Components\Placeholder;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Form;
use Filament\Forms\Get;
use Filament\Infolists\Components\Section;
use Filament\Infolists\Components\TextEntry;
use Filament\Infolists\Infolist;
use Filament\Resources\Resource;
use Filament\Tables\Actions\ViewAction;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Support\HtmlString;
use Illuminate\Support\Str;

/**
 * 02 §25.1: Settings → Terms. Publish and view only — a published version
 * is immutable (database trigger, TermsVersionPolicy), so there is no edit
 * or delete action. Publishing goes through TermsPublisher.
 */
class TermsVersionResource extends Resource
{
    protected static ?string $model = TermsVersion::class;

    protected static ?string $navigationIcon = 'heroicon-o-document-text';

    protected static ?string $navigationGroup = 'Settings';

    protected static ?string $navigationLabel = 'Terms';

    protected static ?string $modelLabel = 'terms version';

    protected static ?int $navigationSort = 30;

    protected static ?string $slug = 'terms';

    protected static ?string $recordTitleAttribute = 'version';

    public static function form(Form $form): Form
    {
        return $form->schema([
            Select::make('kind')->label('Terms')->required()->options(TermsKind::options())->default(TermsKind::Trade->value),
            TextInput::make('version')->label('Version label')->required()->maxLength(32)
                ->regex('/^[0-9A-Za-z._-]{1,32}$/')
                ->helperText('For example 2026-10 or v2.1. Unique for these terms.'),
            DateTimePicker::make('effective_from')->label('Takes effect')->seconds(false)
                ->helperText('Leave empty to take effect immediately. Never in the past.'),
            // Plain Markdown only: no uploaded images or tables in legal text.
            MarkdownEditor::make('body_markdown')->label('Text')->required()->live(debounce: 500)->columnSpanFull()
                ->disableToolbarButtons(['attachFiles', 'table']),
            Placeholder::make('preview')->label('Preview')->columnSpanFull()
                ->content(fn (Get $get): HtmlString => self::render((string) $get('body_markdown'))),
            Placeholder::make('fingerprint')->label('SHA-256 of this text')
                ->content(fn (Get $get): string => trim((string) $get('body_markdown')) === '' ? '—' : hash('sha256', (string) $get('body_markdown'))),
            Checkbox::make('confirmed')->label('I understand this version can never be edited or deleted once published, and that production terms must have been reviewed by a solicitor.')
                ->accepted()->dehydrated(false)->columnSpanFull(),
        ])->columns(2);
    }

    public static function infolist(Infolist $infolist): Infolist
    {
        return $infolist->schema([
            Section::make('Version')->schema([
                TextEntry::make('kind')->formatStateUsing(fn (string $state): string => TermsKind::tryFrom($state)?->label() ?? $state),
                TextEntry::make('version'),
                TextEntry::make('effective_from')->label('Takes effect')->dateTime(),
                TextEntry::make('state')->label('Status')->badge()->state(fn (TermsVersion $record): string => self::state($record)),
                TextEntry::make('publishedBy.email')->label('Published by'),
                TextEntry::make('created_at')->label('Published')->dateTime(),
                TextEntry::make('body_sha256')->label('SHA-256')->copyable()->columnSpanFull(),
            ])->columns(2),
            Section::make('Text')->schema([
                TextEntry::make('body_markdown')->hiddenLabel()->markdown(),
            ]),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table->columns([
            TextColumn::make('kind')->label('Terms')->formatStateUsing(fn (string $state): string => TermsKind::tryFrom($state)?->label() ?? $state),
            TextColumn::make('version'),
            TextColumn::make('effective_from')->label('Takes effect')->dateTime()->sortable(),
            TextColumn::make('state')->label('Status')->badge()->state(fn (TermsVersion $record): string => self::state($record)),
            TextColumn::make('publishedBy.email')->label('Published by'),
            TextColumn::make('body_sha256')->label('SHA-256')->limit(12),
        ])->filters([
            SelectFilter::make('kind')->label('Terms')->options(TermsKind::options()),
        ])->actions([ViewAction::make()])->defaultSort('effective_from', 'desc');
    }

    /** In force, scheduled or superseded — per kind, by effective date. */
    public static function state(TermsVersion $terms): string
    {
        if ($terms->effective_from->isFuture()) {
            return 'Scheduled';
        }

        $current = TermsVersion::current(TermsKind::from($terms->kind));

        return $current?->id === $terms->id ? 'In force' : 'Superseded';
    }

    private static function render(string $markdown): HtmlString
    {
        return new HtmlString(Str::markdown($markdown, ['html_input' => 'escape', 'allow_unsafe_links' => false]));
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListTermsVersions::route('/'),
            'create' => Pages\PublishTermsVersion::route('/publish'),
            'view' => Pages\ViewTermsVersion::route('/{record}'),
        ];
    }
}
