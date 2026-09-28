<?php

namespace App\Filament\Support;

use App\Domain\Identity\CompanyMemberRole;
use App\Http\Requests\Concerns\AuthFields;
use Filament\Forms\Components\Component;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;

final class CompanyMemberFields
{
    /** @return list<Component> */
    public static function settings(): array
    {
        return [
            Select::make('role')->options(CompanyMemberRole::options())->required()->default('buyer'),
            TextInput::make('order_limit')->label('Order limit')->prefix('£')->inputMode('decimal')
                ->helperText('The most a single order may total, in pounds. Leave empty for no limit.')
                ->rules(['nullable', 'regex:/^\\d{1,9}(\\.\\d{1,2})?$/'])
                ->validationMessages(['regex' => 'Enter the order limit in pounds, e.g. 2500 or 2500.50.']),
            Toggle::make('requires_approval')->label('Requires approval')->default(false),
        ];
    }

    /** @return list<Component> */
    public static function invitation(): array
    {
        return [
            TextInput::make('email')->rules(AuthFields::email())->required(),
            TextInput::make('first_name')->required()->maxLength(100),
            TextInput::make('last_name')->required()->maxLength(100),
            ...self::settings(),
        ];
    }
}
