<?php

namespace App\Filament\Resources\CmsPageResource\Pages;

use App\Domain\Cms\CmsPublisher;
use App\Filament\Resources\CmsPageResource;
use App\Models\CmsPage;
use App\Models\User;
use Filament\Actions\Action;
use Filament\Forms\Components\Checkbox;
use Filament\Forms\Components\DateTimePicker;
use Filament\Forms\Components\Placeholder;
use Filament\Forms\Components\Textarea;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\EditRecord;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;
use Illuminate\Validation\ValidationException;

/**
 * 05.11 §2.4: save the draft (CmsPublisher::saveDraft), or save and
 * publish it as a new version, now or at a later time, after a
 * confirmation that shows the text's SHA-256.
 */
class EditCmsPage extends EditRecord
{
    protected static string $resource = CmsPageResource::class;

    public function getTitle(): string
    {
        $record = $this->getRecord();

        return $record instanceof CmsPage ? $record->key()->label() : 'Page';
    }

    /** @param  array<string, mixed>  $data */
    protected function handleRecordUpdate(Model $record, array $data): Model
    {
        assert($record instanceof CmsPage);

        return $this->rethrowOnForm(fn () => app(CmsPublisher::class)->saveDraft(
            $this->actor(),
            $record->key(),
            is_string($data['draft_title'] ?? null) ? $data['draft_title'] : null,
            is_string($data['draft_meta_description'] ?? null) ? $data['draft_meta_description'] : null,
            is_string($data['draft_body_markdown'] ?? null) ? $data['draft_body_markdown'] : null,
        ));
    }

    protected function getSaveFormAction(): Action
    {
        return parent::getSaveFormAction()->label('Save draft');
    }

    protected function getHeaderActions(): array
    {
        return [
            Action::make('publish')
                ->label('Publish')
                ->icon('heroicon-o-globe-alt')
                ->modalHeading('Publish a new version')
                ->modalDescription('The draft is saved first. A published version can never be edited or deleted; a correction is a new version.')
                ->form([
                    DateTimePicker::make('effective_from')->label('Takes effect')->seconds(false)
                        ->helperText('Leave empty to take effect immediately. Never in the past.'),
                    Textarea::make('change_note')->label('What changed (for the history)')->rows(2)->maxLength(500),
                    Placeholder::make('sha')->label('SHA-256 of the text being published')
                        ->content(fn (): string => hash('sha256', (string) ($this->data['draft_body_markdown'] ?? ''))),
                    Checkbox::make('confirmed')
                        ->label('I understand this version can never be edited or deleted once published, and that legal text must have been reviewed by a solicitor.')
                        ->accepted(),
                ])
                ->action(function (array $data): void {
                    $this->save(shouldRedirect: false, shouldSendSavedNotification: false);
                    $record = $this->getRecord();
                    assert($record instanceof CmsPage);
                    $effective = $data['effective_from'] ?? null;

                    $version = $this->rethrowOnForm(fn () => app(CmsPublisher::class)->publish(
                        $this->actor(),
                        $record->key(),
                        is_string($effective) && $effective !== '' ? Carbon::parse($effective) : null,
                        is_string($data['change_note'] ?? null) ? $data['change_note'] : null,
                    ));

                    Notification::make()->success()
                        ->title("Version {$version->version_no} published")
                        ->body($version->effective_from->isFuture() ? 'It takes effect '.$version->effective_from->diffForHumans().'.' : 'It is live now.')
                        ->send();
                }),
        ];
    }

    /**
     * The service's messages, shown on the form's fields.
     *
     * @template T
     *
     * @param  callable(): T  $callback
     * @return T
     */
    private function rethrowOnForm(callable $callback): mixed
    {
        try {
            return $callback();
        } catch (ValidationException $exception) {
            $errors = [];
            foreach ($exception->errors() as $field => $messages) {
                // Draft fields are on the page's form; the rest on the publish modal.
                $errors[str_starts_with($field, 'draft_') ? "data.{$field}" : "mountedActionsData.0.{$field}"] = $messages;
            }

            throw ValidationException::withMessages($errors);
        }
    }

    private function actor(): User
    {
        $user = auth()->user();
        abort_unless($user instanceof User, 403);

        return $user;
    }
}
