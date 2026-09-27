<?php

namespace App\Filament\Resources\TermsVersionResource\Pages;

use App\Domain\Accounts\TermsKind;
use App\Domain\Accounts\TermsPublisher;
use App\Filament\Resources\TermsVersionResource;
use App\Models\User;
use Filament\Resources\Pages\CreateRecord;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;
use Illuminate\Validation\ValidationException;

class PublishTermsVersion extends CreateRecord
{
    protected static string $resource = TermsVersionResource::class;

    protected static ?string $title = 'Publish a new terms version';

    protected static bool $canCreateAnother = false;

    /** @param array<string, mixed> $data */
    protected function handleRecordCreation(array $data): Model
    {
        $actor = auth()->user();
        if (! $actor instanceof User) {
            abort(403);
        }

        $effective = $data['effective_from'] ?? null;

        try {
            return app(TermsPublisher::class)->publish(
                $actor,
                TermsKind::from(is_string($data['kind'] ?? null) ? $data['kind'] : ''),
                is_string($data['version'] ?? null) ? $data['version'] : '',
                is_string($data['body_markdown'] ?? null) ? $data['body_markdown'] : '',
                is_string($effective) && $effective !== '' ? Carbon::parse($effective) : null,
            );
        } catch (ValidationException $exception) {
            $errors = [];
            foreach ($exception->errors() as $field => $messages) {
                $errors["data.{$field}"] = $messages;
            }

            throw ValidationException::withMessages($errors);
        }
    }

    protected function getRedirectUrl(): string
    {
        return TermsVersionResource::getUrl('view', ['record' => $this->record]);
    }
}
