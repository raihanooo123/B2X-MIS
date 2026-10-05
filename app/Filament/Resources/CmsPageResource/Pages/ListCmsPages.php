<?php

namespace App\Filament\Resources\CmsPageResource\Pages;

use App\Filament\Resources\CmsPageResource;
use Filament\Resources\Pages\ListRecords;

/** The five pages; none is created here (05.11 §2.1). */
class ListCmsPages extends ListRecords
{
    protected static string $resource = CmsPageResource::class;
}
