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
            TextInput::make('order_limit_minor')->label('Order limit (pence)')->helperText('Whole pence; leave empty for no limit.')
                ->rules(['nullable', 'integer', 'min:0', 'max:'.PHP_INT_MAX])->inputMode('numeric'),
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
