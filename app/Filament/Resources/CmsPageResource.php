<?php

namespace App\Filament\Resources;

use App\Domain\Cms\CurrentPages;
use App\Domain\Cms\PageKey;
use App\Domain\Cms\SafeMarkdown;
use App\Filament\Resources\CmsPageResource\Pages;
use App\Filament\Resources\CmsPageResource\RelationManagers\VersionsRelationManager;
use App\Filament\Support\SentenceCaseLabels;
use App\Models\CmsPage;
use Filament\Forms\Components\Group;
use Filament\Forms\Components\MarkdownEditor;
use Filament\Forms\Components\Placeholder;
use Filament\Forms\Components\Section;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Form;
use Filament\Forms\Get;
use Filament\Infolists\Components\Group as InfolistGroup;
use Filament\Infolists\Components\Section as InfolistSection;
use Filament\Infolists\Components\TextEntry;
use Filament\Infolists\Infolist;
use Filament\Resources\Resource;
use Filament\Tables\Actions\EditAction;
use Filament\Tables\Actions\ViewAction;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Support\HtmlString;

/**
 * 05.11 §2.4: Content → Pages. The five legal and help pages: edit a draft
 * with a live preview, publish it as a new immutable version (CmsPublisher),
 * and read every version published. Admin only (CmsPagePolicy). Pages are
 * never created or deleted here, and a published version is never edited.
 */
class CmsPageResource extends Resource
{
    use SentenceCaseLabels;

    protected static ?string $model = CmsPage::class;

    protected static ?string $navigationGroup = 'Content';

    protected static ?int $navigationSort = 10;

    protected static ?string $navigationLabel = 'Pages';

    protected static ?string $modelLabel = 'page';

    protected static ?string $slug = 'pages';

    public static function form(Form $form): Form
    {
        return $form
            ->columns(['default' => 1, 'lg' => 3])
            ->schema([
                Group::make()
                    ->columnSpan(['lg' => 2])
                    ->schema([
                        Section::make('Draft')
                            ->icon('heroicon-o-pencil-square')
                            ->description('Saving the draft changes nothing on the website. Publish it to make it public.')
                            ->schema([
                                TextInput::make('draft_title')->label('Title')->required()->maxLength(120),
                                Textarea::make('draft_meta_description')->label('Search engine description')->rows(2)->maxLength(320)
                                    ->helperText('Shown under the title in search results. About 150 characters is ideal.'),
                                // Plain Markdown only: no uploads or tables in legal text.
                                MarkdownEditor::make('draft_body_markdown')->label('Text')->required()->live(debounce: 500)
                                    ->disableToolbarButtons(['attachFiles', 'table']),
                            ]),
                        Section::make('Preview')
                            ->icon('heroicon-o-eye')
                            ->description('Exactly as it will appear on the website.')
                            ->collapsible()
                            ->schema([
                                Placeholder::make('preview')->hiddenLabel()
                                    ->content(fn (Get $get): HtmlString => self::render((string) $get('draft_body_markdown'))),
                            ]),
                    ]),

                Group::make()
                    ->columnSpan(['lg' => 1])
                    ->schema([
                        Section::make('On the website')
                            ->schema([
                                Placeholder::make('address')->label('Address')
                                    ->content(fn (?CmsPage $record): string => $record?->key()->path() ?? '—'),
                                Placeholder::make('in_force')->label('In force')
                                    ->content(fn (?CmsPage $record): string => self::inForce($record) ?? 'Not published yet'),
                                Placeholder::make('scheduled')->label('Scheduled versions')
                                    ->content(fn (?CmsPage $record): string => (string) self::scheduledCount($record)),
                                Placeholder::make('draft_saved')->label('Draft last saved')
                                    ->content(fn (?CmsPage $record): string => $record?->draft_updated_at?->diffForHumans() ?? 'Never'),
                            ]),
                        Section::make('Fingerprint')
                            ->schema([
                                Placeholder::make('fingerprint')->label('SHA-256 of this text')
                                    ->content(fn (Get $get): HtmlString => new HtmlString('<span class="break-all font-mono text-xs">'.e(trim((string) $get('draft_body_markdown')) === '' ? '—' : hash('sha256', (string) $get('draft_body_markdown'))).'</span>'))
                                    ->helperText('Shown again when you publish, so you can match it to the reviewed text.'),
                            ]),
                        Section::make('Added automatically')
                            ->visible(fn (?CmsPage $record): bool => self::generatedBlock($record) !== '')
                            ->schema([
                                Placeholder::make('generated')->hiddenLabel()
                                    ->content(fn (?CmsPage $record): string => self::generatedBlock($record).' Not editable here.'),
                            ]),
                    ]),
            ]);
    }

    public static function infolist(Infolist $infolist): Infolist
    {
        return $infolist
            ->columns(['default' => 1, 'lg' => 3])
            ->schema([
                InfolistGroup::make()
                    ->columnSpan(['lg' => 2])
                    ->schema([
                        InfolistSection::make('Draft')
                            ->icon('heroicon-o-pencil-square')
                            ->description('What will be published next. Edit the page to change it.')
                            ->schema([
                                TextEntry::make('draft_title')->label('Title')->placeholder('No draft')
                                    ->size(TextEntry\TextEntrySize::Large)->weight('semibold'),
                                TextEntry::make('draft_meta_description')->label('Search engine description')->placeholder('—'),
                                TextEntry::make('draft_body_markdown')->label('Text')->placeholder('No draft text.')
                                    ->formatStateUsing(fn (string $state): HtmlString => self::render($state)),
                            ]),
                    ]),
                InfolistGroup::make()
                    ->columnSpan(['lg' => 1])
                    ->schema([
                        InfolistSection::make('On the website')
                            ->schema([
                                TextEntry::make('address')->label('Address')->state(fn (CmsPage $record): string => $record->key()->path())
                                    ->url(fn (CmsPage $record): string => url($record->key()->path()), shouldOpenInNewTab: true),
                                TextEntry::make('in_force')->label('In force')->badge()
                                    ->state(fn (CmsPage $record): string => self::inForce($record) ?? 'Not published')
                                    ->color(fn (CmsPage $record): string => self::inForce($record) === null ? 'gray' : 'success'),
                                TextEntry::make('scheduled')->label('Scheduled versions')->state(fn (CmsPage $record): int => self::scheduledCount($record)),
                                TextEntry::make('draft_updated_at')->label('Draft last saved')->dateTime()->placeholder('Never'),
                            ]),
                    ]),
            ]);
    }

    public static function table(Table $table): Table
    {
        return $table->columns([
            TextColumn::make('page_key')->label('Page')->weight('medium')
                ->formatStateUsing(fn (string $state): string => PageKey::from($state)->label())
                ->description(fn (CmsPage $record): string => $record->key()->path()),
            TextColumn::make('in_force')->label('In force')->badge()->color('success')->placeholder('Not published')
                ->state(fn (CmsPage $record): ?string => self::inForce($record)),
            TextColumn::make('scheduled')->label('Scheduled')->placeholder('—')->alignEnd()
                ->state(fn (CmsPage $record): ?string => ($n = self::scheduledCount($record)) === 0 ? null : (string) $n),
            TextColumn::make('draft_updated_at')->label('Draft saved')->dateTime()->placeholder('Never'),
        ])->actions([
            ViewAction::make()->iconButton()->tooltip('View'),
            EditAction::make()->iconButton()->tooltip('Edit draft'),
        ])
            ->paginated(false)
            ->defaultSort('id');
    }

    private static function inForce(?CmsPage $record): ?string
    {
        return $record === null || ($v = CurrentPages::version($record->key())) === null ? null : "Version {$v->version_no}";
    }

    private static function scheduledCount(?CmsPage $record): int
    {
        return $record === null ? 0 : $record->versions()->where('effective_from', '>', now())->count();
    }

    public static function getRelations(): array
    {
        return [VersionsRelationManager::class];
    }

    /** The same renderer as the public page (SafeMarkdown), so the preview cannot differ from it. */
    public static function render(string $markdown): HtmlString
    {
        return SafeMarkdown::toHtmlString($markdown);
    }

    private static function generatedBlock(?CmsPage $record): string
    {
        return match ($record?->key()) {
            PageKey::Cookies => 'The table of cookies this site uses (from the cookie registry), below your text.',
            PageKey::Returns => 'The statutory cancellation and returns statement, the same text as checkout and the order email, below your text.',
            PageKey::Contact => 'Your contact details and registered company details (Storefront settings and seller details), below your text.',
            default => '',
        };
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListCmsPages::route('/'),
            'view' => Pages\ViewCmsPage::route('/{record}'),
            'edit' => Pages\EditCmsPage::route('/{record}/edit'),
        ];
    }
}
