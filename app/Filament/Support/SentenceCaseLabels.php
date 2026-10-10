<?php

namespace App\Filament\Support;

use Illuminate\Support\Str;

/**
 * Page titles and menu items in sentence case ("Purchase orders"), as the
 * rest of the panel, instead of Filament's title case ("Purchase Orders").
 * Labels that carry their own capitals, such as "SKUs", are kept as given.
 */
trait SentenceCaseLabels
{
    public static function getTitleCaseModelLabel(): string
    {
        return Str::ucfirst(static::getModelLabel());
    }

    public static function getTitleCasePluralModelLabel(): string
    {
        return Str::ucfirst(static::getPluralModelLabel());
    }
}
