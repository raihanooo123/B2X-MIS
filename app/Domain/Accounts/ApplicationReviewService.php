<?php

namespace App\Domain\Accounts;

use App\Domain\Audit\AuditAction;
use App\Domain\Audit\AuditEntry;
use App\Domain\Audit\AuditLogger;
use App\Domain\Delivery\ZoneResolver;
use App\Domain\Notifications\Notices\ApplicationApproved;
use App\Domain\Notifications\Notices\ApplicationInfoRequested;
use App\Domain\Notifications\Notices\ApplicationRejected;
use App\Domain\Notifications\Notifications;
use App\Domain\Pricing\PricingCache;
use App\Domain\Reference\NumberSequenceService;
use App\Models\Address;
use App\Models\B2bApplication;
use App\Models\Company;
use App\Models\CompanyUser;
use App\Models\PriceTier;
use App\Models\Role;
use App\Models\User;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;

/**
 * 05.2 §4–5.7: a reviewer (`admin` or `accounts`, B2bApplicationPolicy)
 * reviews a trade application. Each method
 * is one edge of the state machine; the policy ability of the same name
 * allows it only from the state it leaves:
 *
 *   submitted      → in_review       startReview
 *   in_review      → info_requested  requestInfo
 *   info_requested → in_review       resumeReview
 *   in_review      → approved        approve
 *   in_review      → rejected        reject
 *
 * Concurrency: every transition locks the `admin` row of `roles` (as the
 * staff and customer services do — StaffRoleService takes that lock for a
 * change to any role, `accounts` included, so the actor's authority is
 * read after any competing role change commits), then the application
 * row, and re-checks the policy against that locked row. Two reviewers acting
 * on one application therefore serialise, and the second finds it has
 * already left the state its action needs.
 *
 * The applicant's `users` row is locked next, so an approval and a
 * concurrent suspension of the applicant serialise and a new company is
 * never left without an active owner (05.13 §9). Approval (§5.6) then
 * creates the company and takes the `account_code` number last. The
 * company is new, so no existing `companies` row is locked: the order is
 * roles → b2b_applications → users, and CustomerSuspensionService (roles
 * → companies → users) never takes an application lock, so the two
 * cannot deadlock. Notices and the pricing cache flush run after commit.
 */
final class ApplicationReviewService
{
    /** 05.2 §5.7's default; the configuration key is not yet named. */
    public const COOLING_PERIOD_DAYS = 90;

    public function __construct(
        private readonly AuditLogger $audit = new AuditLogger,
        private readonly Notifications $notifications = new Notifications,
        private readonly NumberSequenceService $numbers = new NumberSequenceService,
        private readonly ZoneResolver $zones = new ZoneResolver,
        private readonly PricingCache $pricingCache = new PricingCache,
    ) {}

    public function startReview(B2bApplication $application, User $actor): void
    {
        $this->transition($application, $actor, 'startReview', function (B2bApplication $locked, User $reviewer): void {
            $locked->forceFill(['status' => 'in_review', 'reviewer_user_id' => $reviewer->id])->save();
            $this->record($reviewer, $locked, AuditAction::ApplicationReviewStarted, 'submitted', ['status' => 'in_review']);
        });
    }

    public function requestInfo(B2bApplication $application, User $actor, string $request): void
    {
        $request = trim($request);
        if ($request === '') {
            throw ValidationException::withMessages(['info_request' => 'Say what information you need.']);
        }

        $this->transition($application, $actor, 'requestInfo', function (B2bApplication $locked, User $reviewer, User $applicant) use ($request): void {
            $locked->forceFill(['status' => 'info_requested', 'info_request' => $request, 'reviewer_user_id' => $reviewer->id])->save();
            $this->record($reviewer, $locked, AuditAction::ApplicationInfoRequested, 'in_review', ['status' => 'info_requested']);

            $this->notifications->toUser(new ApplicationInfoRequested($locked->id, $request), $applicant);
        });
    }

    public function resumeReview(B2bApplication $application, User $actor): void
    {
        $this->transition($application, $actor, 'resumeReview', function (B2bApplication $locked, User $reviewer): void {
            $locked->forceFill(['status' => 'in_review', 'reviewer_user_id' => $reviewer->id])->save();
            $this->record($reviewer, $locked, AuditAction::ApplicationReviewResumed, 'info_requested', ['status' => 'in_review']);
        });
    }

    /**
     * 05.2 §5.7: the internal reason is mandatory and never shown to the
     * applicant. A remediable rejection may be followed by a new
     * application at once; otherwise after the cooling period.
     */
    public function reject(B2bApplication $application, User $actor, string $internalReason, bool $remediable): void
    {
        $internalReason = trim($internalReason);
        if ($internalReason === '') {
            throw ValidationException::withMessages(['review_note' => 'Record the internal reason for rejecting.']);
        }

        $this->transition($application, $actor, 'reject', function (B2bApplication $locked, User $reviewer, User $applicant) use ($internalReason, $remediable): void {
            $reviewedAt = now();
            $locked->forceFill([
                'status' => 'rejected',
                'review_note' => $internalReason,
                'reviewer_user_id' => $reviewer->id,
                'reviewed_at' => $reviewedAt,
            ])->save();
            $this->record($reviewer, $locked, AuditAction::ApplicationRejected, 'in_review', ['status' => 'rejected', 'remediable' => $remediable]);

            $reapplyFrom = $remediable ? null : $reviewedAt->copy()->addDays(self::COOLING_PERIOD_DAYS)->toIso8601String();
            $this->notifications->toUser(new ApplicationRejected($locked->id, $remediable, $reapplyFrom), $applicant);
        });
    }

    /**
     * 05.2 §5.6, one transaction. The applicant's existing account is
     * linked as the company's owner (05.13 §5.1, "link, not create").
     */
    public function approve(B2bApplication $application, User $actor, ApprovalTerms $terms): Company
    {
        $company = null;

        $this->transition($application, $actor, 'approve', function (B2bApplication $locked, User $reviewer, User $applicant) use ($terms, &$company): void {
            if ($terms->creditLimitMinor > 0) {
                Gate::forUser($reviewer)->authorize('grantCredit', $locked);
            }
            if ($applicant->status !== 'active') {
                throw ValidationException::withMessages(['application' => 'The applicant\'s account is not active, so it cannot own a trade account.']);
            }
            if ($applicant->email_verified_at === null) {
                throw ValidationException::withMessages(['application' => 'The applicant has not confirmed their email address yet.']);
            }
            if (! PriceTier::query()->whereKey($terms->priceTierId)->exists()) {
                throw ValidationException::withMessages(['price_tier_id' => 'Choose a price tier.']);
            }
            if ($locked->vat_number !== null) {
                $existing = Company::query()->where('vat_number', $locked->vat_number)->first(['id', 'name', 'account_code']);
                if ($existing !== null) {
                    throw ValidationException::withMessages(['application' => "VAT number {$locked->vat_number} is already on file for {$existing->name} ({$existing->account_code}). Adding a site to an existing company is not supported yet."]);
                }
            }
            $address = $this->tradingAddress($locked);

            // Last, after every check (02 §11.3).
            $accountCode = $this->numbers->next('account_code');

            $company = new Company;
            $company->forceFill([
                'account_code' => $accountCode,
                'name' => $locked->company_name,
                'vat_number' => $locked->vat_number,
                'registration_number' => $locked->registration_number,
                'status' => 'approved',
                'price_tier_id' => $terms->priceTierId,
                'payment_terms' => $terms->paymentTerms->value,
                'credit_limit_minor' => $terms->creditLimitMinor,
                'approved_at' => now(),
                'approved_by_user_id' => $reviewer->id,
            ])->save();

            CompanyUser::create([
                'company_id' => $company->id,
                'user_id' => $applicant->id,
                'role' => 'owner',
                'is_default_contact' => true,
            ]);

            Address::create([
                'company_id' => $company->id,
                'label' => 'Trading address',
                'contact_name' => $locked->contact_name,
                'phone' => $locked->contact_phone,
                'line1' => $address['line1'],
                'line2' => $address['line2'],
                'city' => $address['city'],
                'county' => $address['county'],
                'postcode' => $address['postcode'],
                'country_code' => $address['country_code'],
                'address_type' => 'both',
                'is_default' => true,
                'delivery_zone_id' => $this->zones->resolve($address['postcode'], $address['country_code'])->zone?->id,
            ]);

            $locked->forceFill([
                'status' => 'approved',
                'company_id' => $company->id,
                'granted_tier_id' => $terms->priceTierId,
                'reviewer_user_id' => $reviewer->id,
                'reviewed_at' => now(),
            ])->save();

            $this->record($reviewer, $locked, AuditAction::ApplicationApproved, 'in_review', [
                'status' => 'approved',
                'company_id' => $company->id,
                'owner_user_id' => $applicant->id,
                'price_tier_id' => $terms->priceTierId,
                'payment_terms' => $terms->paymentTerms->value,
                'credit_limit_minor' => $terms->creditLimitMinor,
            ], companyId: $company->id);

            if ($terms->creditLimitMinor > 0) {
                $this->audit->record(new AuditEntry(
                    action: AuditAction::CreditLimitChanged,
                    actorType: 'user',
                    actorUserId: $reviewer->id,
                    companyId: $company->id,
                    subjectType: 'company',
                    subjectId: $company->id,
                    before: ['credit_limit_minor' => 0],
                    after: ['credit_limit_minor' => $terms->creditLimitMinor],
                ));
            }

            $this->notifications->toUser(new ApplicationApproved($locked->id), $applicant);
            $companyId = $company->id;
            DB::afterCommit(fn () => $this->pricingCache->forgetCompanyLists($companyId));
        });

        if (! $company instanceof Company) {
            throw new \LogicException('Approval did not create a company.');
        }

        return $company;
    }

    /**
     * @param  callable(B2bApplication, User, User): void  $apply  locked application, current actor, applicant
     */
    private function transition(B2bApplication $application, User $actor, string $ability, callable $apply): void
    {
        DB::transaction(function () use ($application, $actor, $ability, $apply): void {
            if (Role::query()->where('code', 'admin')->lockForUpdate()->first() === null) {
                throw ValidationException::withMessages(['application' => 'Staff roles are not configured.']);
            }

            $locked = B2bApplication::query()->lockForUpdate()->findOrFail($application->id);
            $currentActor = User::query()->findOrFail($actor->id);
            Gate::forUser($currentActor)->authorize($ability, $locked);

            // The policy refuses a NULL applicant, so this is always a user.
            $applicant = User::query()->lockForUpdate()->findOrFail($locked->applicant_user_id);
            if ($applicant->id === $currentActor->id) {
                throw new AuthorizationException('Reviewers cannot review their own application.');
            }

            $apply($locked, $currentActor, $applicant);
        });
    }

    /**
     * The application's `address` jsonb, as captured by RegisterTradeRequest.
     *
     * @return array{line1: string, line2: ?string, city: string, county: ?string, postcode: string, country_code: string}
     */
    private function tradingAddress(B2bApplication $application): array
    {
        $raw = $application->address;
        $text = fn (string $key): ?string => is_string($raw[$key] ?? null) && trim($raw[$key]) !== '' ? trim($raw[$key]) : null;

        $line1 = $text('line1');
        $city = $text('city');
        $postcode = $text('postcode');
        if ($line1 === null || $city === null || $postcode === null) {
            throw ValidationException::withMessages(['application' => 'The application\'s trading address is incomplete.']);
        }

        return [
            'line1' => $line1,
            'line2' => $text('line2'),
            'city' => $city,
            'county' => $text('county'),
            'postcode' => strtoupper($postcode),
            'country_code' => strtoupper($text('country_code') ?? 'GB'),
        ];
    }

    /**
     * @param  array<string, bool|int|string>  $after
     */
    private function record(User $actor, B2bApplication $application, AuditAction $action, string $from, array $after, ?int $companyId = null): void
    {
        $this->audit->record(new AuditEntry(
            action: $action,
            actorType: 'user',
            actorUserId: $actor->id,
            companyId: $companyId,
            subjectType: 'b2b_application',
            subjectId: $application->id,
            before: ['status' => $from],
            after: $after,
        ));
    }
}
