<?php

namespace App\Filament\Resources\CmsPageResource\RelationManagers;

use App\Domain\Cms\CurrentPages;
use App\Domain\Cms\SafeMarkdown;
use App\Models\CmsPage;
use App\Models\CmsPageVersion;
use Filament\Infolists\Components\TextEntry;
use Filament\Infolists\Infolist;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Tables\Actions\ViewAction;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;

/**
 * 05.11 §2.4: every published version of the page, read-only — the
 * `cms_page_versions_immutable` trigger refuses any change.
 */
class VersionsRelationManager extends RelationManager
{
    protected static string $relationship = 'versions';

    protected static ?string $title = 'Published versions';

    public function isReadOnly(): bool
    {
        return true;
    }

    public function infolist(Infolist $infolist): Infolist
    {
        return $infolist->schema([
            TextEntry::make('version_no')->label('Version'),
            TextEntry::make('effective_from')->label('Takes effect')->dateTime(),
            TextEntry::make('publishedBy.email')->label('Published by'),
            TextEntry::make('change_note')->label('What changed')->placeholder('—'),
            TextEntry::make('title'),
            TextEntry::make('meta_description')->label('Search engine description')->placeholder('—'),
            TextEntry::make('body_sha256')->label('SHA-256')->copyable()->columnSpanFull(),
            // SafeMarkdown, not Filament's own markdown(): one renderer for page text everywhere.
            TextEntry::make('body_markdown')->label('Text')->columnSpanFull()
                ->formatStateUsing(fn (string $state) => SafeMarkdown::toHtmlString($state)),
        ])->columns(2);
    }

    public function table(Table $table): Table
    {
        return $table->columns([
            TextColumn::make('version_no')->label('Version'),
            TextColumn::make('title'),
            TextColumn::make('effective_from')->label('Takes effect')->dateTime(),
            TextColumn::make('state')->label('Status')->badge()->state(fn (CmsPageVersion $record): string => $this->state($record)),
            TextColumn::make('publishedBy.email')->label('Published by'),
            TextColumn::make('body_sha256')->label('SHA-256')->limit(12),
        ])->actions([ViewAction::make()])->defaultSort('version_no', 'desc');
    }

    private function state(CmsPageVersion $version): string
    {
        if ($version->effective_from->isFuture()) {
            return 'Scheduled';
        }
        $page = $this->getOwnerRecord();
        assert($page instanceof CmsPage);

        return CurrentPages::version($page->key())?->id === $version->id ? 'In force' : 'Superseded';
    }
}
