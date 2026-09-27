<?php

namespace App\Filament\Resources\TradeApplicationResource\Pages;

use App\Domain\Accounts\ApplicationReviewService;
use App\Domain\Accounts\ApplicationSettings;
use App\Domain\Accounts\ApprovalTerms;
use App\Domain\Accounts\RejectionCategory;
use App\Domain\Billing\PaymentTerms;
use App\Domain\Notifications\Notices\ApplicationRejected;
use App\Filament\Resources\TradeApplicationResource;
use App\Filament\Support\MoneyFormatter;
use App\Models\B2bApplication;
use App\Models\PriceTier;
use App\Models\User;
use Filament\Actions\Action;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\ViewRecord;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;

class ViewTradeApplication extends ViewRecord
{
    protected static string $resource = TradeApplicationResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Action::make('startReview')
                ->label('Start review')
                ->requiresConfirmation()
                ->modalDescription('You become the reviewer. The applicant still sees "Under review".')
                ->visible(fn (): bool => Gate::allows('startReview', $this->application()))
                ->action(fn () => $this->review('Review started', fn (ApplicationReviewService $service, User $actor) => $service->startReview($this->application(), $actor))),
            Action::make('approve')
                ->label('Approve')
                ->color('success')
                ->visible(fn (): bool => Gate::allows('approve', $this->application()))
                ->modalDescription('Creates the trade account, makes the applicant its owner, copies the trading address and emails the account code and terms.')
                ->form([
                    Select::make('price_tier_id')->label('Price tier')->required()
                        ->options(fn (): array => PriceTier::query()->orderBy('position')->pluck('name', 'id')->all())
                        ->default(fn (): mixed => $this->application()->requested_tier_id ?? PriceTier::query()->where('is_default', true)->value('id')),
                    Select::make('payment_terms')->label('Payment terms')->required()
                        ->options(PaymentTerms::options())->default(PaymentTerms::Prepay->value),
                    TextInput::make('credit_limit')->label('Credit limit (£)')->required()->default('0')
                        ->regex('/^\d{1,9}(\.\d{1,2})?$/')
                        ->helperText('Pounds and pence, for example 2500 or 2500.00. Leave at 0 for no credit.'),
                ])
                ->action(fn (array $data) => $this->approve($data)),
            Action::make('requestInfo')
                ->label('Request information')
                ->visible(fn (): bool => Gate::allows('requestInfo', $this->application()))
                ->form([
                    Textarea::make('info_request')->label('What do you need?')->required()->maxLength(2000)
                        ->helperText('Sent to the applicant word for word.'),
                ])
                ->action(fn (array $data) => $this->review('Information requested', fn (ApplicationReviewService $service, User $actor) => $service->requestInfo($this->application(), $actor, self::text($data, 'info_request')))),
            Action::make('resumeReview')
                ->label('Resume review')
                ->requiresConfirmation()
                ->modalDescription('Use this once the information you asked for has arrived.')
                ->visible(fn (): bool => Gate::allows('resumeReview', $this->application()))
                ->action(fn () => $this->review('Review resumed', fn (ApplicationReviewService $service, User $actor) => $service->resumeReview($this->application(), $actor))),
            Action::make('reject')
                ->label('Reject')
                ->color('danger')
                ->visible(fn (): bool => Gate::allows('reject', $this->application()))
                ->modalDescription('The applicant keeps their login and can buy at standard prices. They are emailed your message, or a neutral decline if you leave it empty.')
                ->form([
                    Select::make('rejection_category')->label('Category')->required()->options(RejectionCategory::options()),
                    Textarea::make('review_note')->label('Internal reason')->required()->maxLength(2000)
                        ->helperText('Never shown to the applicant.'),
                    Textarea::make('applicant_message')->label('Message to the applicant')->maxLength(2000)
                        ->helperText('Optional. Sent in the rejection email instead of: "'.ApplicationRejected::DEFAULT_MESSAGE.'"'),
                    Toggle::make('remediable')->label('They may reapply straight away')
                        ->helperText(fn (): string => 'Off: they can reapply after '.app(ApplicationSettings::class)->reapplyCoolingDays().' days.'),
                ])
                ->action(fn (array $data) => $this->reject($data)),
        ];
    }

    /** @param array<string, mixed> $data */
    private function reject(array $data): void
    {
        $category = RejectionCategory::tryFrom(self::text($data, 'rejection_category'));
        if ($category === null) {
            Notification::make()->title('Choose a rejection category.')->danger()->send();

            return;
        }
        $message = self::text($data, 'applicant_message');

        $this->review('Application rejected', fn (ApplicationReviewService $service, User $actor) => $service->reject(
            $this->application(), $actor, self::text($data, 'review_note'), $category, (bool) ($data['remediable'] ?? false), $message === '' ? null : $message,
        ));
    }

    /** @param array<string, mixed> $data */
    private function approve(array $data): void
    {
        $limit = MoneyFormatter::decimalStringToMinor(self::text($data, 'credit_limit'));
        $terms = PaymentTerms::tryFrom(self::text($data, 'payment_terms'));
        $tierId = $data['price_tier_id'] ?? null;
        if ($limit === null || $limit < 0 || $terms === null || ! is_numeric($tierId)) {
            Notification::make()->title('Check the tier, terms and credit limit.')->danger()->send();

            return;
        }

        $this->review('Application approved', fn (ApplicationReviewService $service, User $actor) => $service->approve(
            $this->application(), $actor, new ApprovalTerms((int) $tierId, $terms, $limit),
        ));
    }

    /**
     * @param  callable(ApplicationReviewService, User): mixed  $operation
     */
    private function review(string $done, callable $operation): void
    {
        $actor = auth()->user();
        if (! $actor instanceof User) {
            abort(403);
        }

        try {
            $operation(app(ApplicationReviewService::class), $actor);
            Notification::make()->title($done)->success()->send();
        } catch (ValidationException $exception) {
            Notification::make()->title(collect($exception->errors())->flatten()->first() ?? 'Unable to update the application')->danger()->send();
        }

        $this->application()->refresh();
    }

    /** @param array<string, mixed> $data */
    private static function text(array $data, string $key): string
    {
        return is_string($data[$key] ?? null) ? $data[$key] : '';
    }

    private function application(): B2bApplication
    {
        $record = $this->getRecord();
        if (! $record instanceof B2bApplication) {
            throw new \LogicException('This page requires a trade application.');
        }

        return $record;
    }
}
