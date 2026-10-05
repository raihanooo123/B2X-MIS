<?php

namespace App\Filament\Resources;

use App\Domain\Cms\CurrentPages;
use App\Domain\Cms\PageKey;
use App\Domain\Cms\SafeMarkdown;
use App\Filament\Resources\CmsPageResource\Pages;
use App\Filament\Resources\CmsPageResource\RelationManagers\VersionsRelationManager;
use App\Models\CmsPage;
use Filament\Forms\Components\MarkdownEditor;
use Filament\Forms\Components\Placeholder;
use Filament\Forms\Components\Section;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Form;
use Filament\Forms\Get;
use Filament\Resources\Resource;
use Filament\Tables\Actions\EditAction;
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
    protected static ?string $model = CmsPage::class;

    protected static ?string $navigationIcon = 'heroicon-o-document-duplicate';

    protected static ?string $navigationGroup = 'Content';

    protected static ?string $navigationLabel = 'Pages';

    protected static ?string $modelLabel = 'page';

    protected static ?string $slug = 'pages';

    public static function form(Form $form): Form
    {
        return $form->schema([
            Section::make('Draft')
                ->description('Saving the draft changes nothing on the website. Publish it to make it public.')
                ->schema([
                    TextInput::make('draft_title')->label('Title')->required()->maxLength(120),
                    Textarea::make('draft_meta_description')->label('Search engine description')->rows(2)->maxLength(320)
                        ->helperText('Shown under the title in search results. About 150 characters is ideal.'),
                    // Plain Markdown only: no uploads or tables in legal text.
                    MarkdownEditor::make('draft_body_markdown')->label('Text')->required()->live(debounce: 500)
                        ->disableToolbarButtons(['attachFiles', 'table']),
                    Placeholder::make('fingerprint')->label('SHA-256 of this text')
                        ->content(fn (Get $get): string => trim((string) $get('draft_body_markdown')) === '' ? '—' : hash('sha256', (string) $get('draft_body_markdown'))),
                ]),
            Section::make('Preview')->schema([
                Placeholder::make('preview')->hiddenLabel()
                    ->content(fn (Get $get): HtmlString => self::render((string) $get('draft_body_markdown'))),
                Placeholder::make('generated')->label('Added automatically, not editable')
                    ->content(fn (?CmsPage $record): string => self::generatedBlock($record))
                    ->visible(fn (?CmsPage $record): bool => self::generatedBlock($record) !== ''),
            ]),
        ])->columns(1);
    }

    public static function table(Table $table): Table
    {
        return $table->columns([
            TextColumn::make('page_key')->label('Page')->formatStateUsing(fn (string $state): string => PageKey::from($state)->label()),
            TextColumn::make('path')->label('Address')->state(fn (CmsPage $record): string => $record->key()->path()),
            TextColumn::make('in_force')->label('In force')->placeholder('Not published')
                ->state(fn (CmsPage $record): ?string => ($v = CurrentPages::version($record->key())) === null ? null : "Version {$v->version_no}"),
            TextColumn::make('scheduled')->label('Scheduled')->placeholder('—')
                ->state(fn (CmsPage $record): ?string => ($n = $record->versions()->where('effective_from', '>', now())->count()) === 0 ? null : (string) $n),
            TextColumn::make('draft_updated_at')->label('Draft saved')->dateTime()->placeholder('Never'),
        ])->actions([EditAction::make()->label('Edit')])
            ->paginated(false)
            ->defaultSort('id');
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
            'edit' => Pages\EditCmsPage::route('/{record}/edit'),
        ];
    }
}
