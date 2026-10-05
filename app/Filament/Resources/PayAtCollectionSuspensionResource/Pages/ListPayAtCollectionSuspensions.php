<?php

namespace App\Filament\Resources\PayAtCollectionSuspensionResource\Pages;

use App\Domain\Collection\CashRefused;
use App\Domain\Collection\PayAtCollectionSuspensions;
use App\Filament\Resources\PayAtCollectionSuspensionResource;
use App\Models\Company;
use App\Models\PayAtCollectionSuspension;
use App\Models\User;
use Filament\Actions\Action;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Get;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\ListRecords;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Gate;

class ListPayAtCollectionSuspensions extends ListRecords
{
    protected static string $resource = PayAtCollectionSuspensionResource::class;

    /** 05.6 §7A.11: staff may suspend a customer by hand, with a reason. */
    protected function getHeaderActions(): array
    {
        return [
            Action::make('suspend')
                ->label('Suspend a customer')
                ->icon('heroicon-o-no-symbol')
                ->color('danger')
                ->visible(fn () => Gate::allows('create', PayAtCollectionSuspension::class))
                ->form([
                    Select::make('kind')->label('Customer')->required()->live()
                        ->options(['user' => 'A public customer (by email)', 'company' => 'A trade account (by account code)']),
                    TextInput::make('identifier')->required()->maxLength(254)
                        ->label(fn (Get $get) => $get('kind') === 'company' ? 'Account code' : 'Email'),
                    Textarea::make('note')->label('Reason')->required()->maxLength(500),
                ])
                ->action(function (array $data): void {
                    $identifier = trim((string) $data['identifier']);
                    $userId = null;
                    $companyId = null;
                    if ($data['kind'] === 'company') {
                        $companyId = Company::query()->where('account_code', strtoupper($identifier))->value('id');
                    } else {
                        $userId = User::query()->whereRaw('lower(email) = ?', [mb_strtolower($identifier)])->value('id');
                    }
                    if ($userId === null && $companyId === null) {
                        Notification::make()->title('No customer found with that '.($data['kind'] === 'company' ? 'account code' : 'email').'.')->danger()->send();

                        return;
                    }

                    try {
                        (new PayAtCollectionSuspensions)->suspend($userId === null ? null : (int) $userId, $companyId === null ? null : (int) $companyId, (int) Auth::id(), (string) $data['note']);
                        Notification::make()->title('Pay at collection suspended')->success()->send();
                    } catch (CashRefused $e) {
                        Notification::make()->title($e->getMessage())->danger()->send();
                    }
                }),
        ];
    }
}
