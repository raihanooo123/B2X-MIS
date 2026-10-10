<?php

namespace App\Domain\Accounts;

use App\Domain\Audit\AuditAction;
use App\Domain\Audit\AuditEntry;
use App\Domain\Audit\AuditLogger;
use App\Domain\Notifications\Notices\ApplicationReplyReceived;
use App\Domain\Notifications\Notifications;
use App\Models\Attachment;
use App\Models\B2bApplication;
use App\Models\User;
use App\Support\DisplayTime;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Storage;
use Throwable;

/**
 * 05.17 §2 — the applicant's own side of a trade application: its status,
 * answering an information request, and withdrawing.
 *
 * Only the applicant (`applicant_user_id`) sees or changes it
 * (B2bApplicationPolicy::viewOwn/replyOwn/withdrawOwn). What they see is
 * customer-safe: the request text, the message a reviewer chose to send,
 * dates and the next step — never the internal reason, the rejection
 * category or the verification evidence.
 *
 * A reply moves info_requested → in_review, stores the evidence as private
 * attachments, records the reply text in the audit trail beside the
 * reviewers' own history (nothing earlier is overwritten), and tells the
 * reviewer after commit. Withdrawal affects an open application only.
 * Each locks just the application row; reviewers lock `roles` first, then
 * the application, so the two never wait on each other in opposite order.
 */
final class ApplicantApplications
{
    public const REPLY_MAX = 2000;

    public const FILES_MAX = 3;

    public const FILE_MAX_KB = 5120;

    public const FILE_TYPES = ['pdf', 'jpg', 'jpeg', 'png'];

    private const STATUS_LABELS = [
        'submitted' => 'Received',
        'in_review' => 'Being reviewed',
        'info_requested' => 'We need more information',
        'approved' => 'Approved',
        'rejected' => 'Not approved',
        'withdrawn' => 'Withdrawn',
    ];

    public function __construct(
        private readonly AuditLogger $audit = new AuditLogger,
        private readonly Notifications $notifications = new Notifications,
    ) {}

    /** The applicant's applications, newest first. */
    public function latestFor(User $user): ?B2bApplication
    {
        return B2bApplication::query()->where('applicant_user_id', $user->id)
            ->orderByDesc('submitted_at')->orderByDesc('id')->first();
    }

    /** @return array<string, mixed> customer-safe status, for the applicant only */
    public function status(B2bApplication $application, User $viewer): array
    {
        $reapplyAfter = $application->reapply_after;

        return [
            'id' => $application->public_id,
            'company_name' => $application->company_name,
            'status' => $application->status,
            'status_label' => self::STATUS_LABELS[$application->status] ?? $application->status,
            'submitted_at' => $application->submitted_at->toIso8601ZuluString(),
            'reviewed_at' => $application->reviewed_at?->toIso8601ZuluString(),
            'info_request' => $application->status === 'info_requested' ? $application->info_request : null,
            'applicant_message' => $application->status === 'rejected' ? $application->applicant_message : null,
            'rejection' => $application->status !== 'rejected' ? null : [
                'remediable' => (bool) $application->rejection_remediable,
                'reapply_after' => $reapplyAfter?->toIso8601ZuluString(),
                'reapply_after_display' => $reapplyAfter === null ? null : DisplayTime::format($reapplyAfter, DisplayTime::DATE),
                'may_reapply_now' => $reapplyAfter === null || $reapplyAfter->isPast(),
            ],
            'next_step' => $this->nextStep($application),
            'can_reply' => Gate::forUser($viewer)->allows('replyOwn', $application),
            'can_withdraw' => Gate::forUser($viewer)->allows('withdrawOwn', $application),
            'history' => array_values(B2bApplication::query()->where('applicant_user_id', $viewer->id)->whereKeyNot($application->id)
                ->orderByDesc('submitted_at')->orderByDesc('id')->limit(10)->get(['public_id', 'company_name', 'status', 'submitted_at'])
                ->map(fn (B2bApplication $a): array => [
                    'id' => $a->public_id,
                    'company_name' => $a->company_name,
                    'status' => $a->status,
                    'status_label' => self::STATUS_LABELS[$a->status] ?? $a->status,
                    'submitted_at' => $a->submitted_at->toIso8601ZuluString(),
                ])->all()),
        ];
    }

    /**
     * @param  list<UploadedFile>  $files  already validated (type, size, count)
     */
    public function reply(B2bApplication $application, User $applicant, string $message, array $files): void
    {
        $stored = [];
        try {
            foreach ($files as $file) {
                $stored[] = $this->storeEvidence($application, $file);
            }

            $reviewerId = DB::transaction(function () use ($application, $applicant, $message, $stored): ?int {
                $locked = B2bApplication::query()->lockForUpdate()->findOrFail($application->id);
                Gate::forUser($applicant)->authorize('replyOwn', $locked);

                $ids = [];
                foreach ($stored as [$disk, $path, $name, $mime, $size]) {
                    $ids[] = Attachment::query()->create([
                        'attachable_type' => 'b2b_application',
                        'attachable_id' => $locked->id,
                        'disk' => $disk,
                        'path' => $path,
                        'original_name' => $name,
                        'mime_type' => $mime,
                        'size_bytes' => $size,
                        'is_customer_visible' => false,
                        'uploaded_by_user_id' => $applicant->id,
                    ])->id;
                }

                $locked->forceFill(['status' => 'in_review'])->save();
                $this->audit->record(new AuditEntry(
                    action: AuditAction::ApplicationApplicantReplied,
                    actorType: 'user',
                    actorUserId: $applicant->id,
                    subjectType: 'b2b_application',
                    subjectId: $locked->id,
                    before: ['status' => 'info_requested'],
                    after: ['status' => 'in_review', 'attachment_ids' => implode(',', $ids)],
                    reason: $message,
                ));

                return $locked->reviewer_user_id;
            });
        } catch (Throwable $e) {
            foreach ($stored as [$disk, $path]) {
                Storage::disk($disk)->delete($path);
            }
            throw $e;
        }

        $reviewer = $reviewerId === null ? null : User::query()->find($reviewerId);
        if ($reviewer !== null) {
            DB::afterCommit(fn () => $this->notifications->toUser(new ApplicationReplyReceived($application->id), $reviewer));
        }
    }

    public function withdraw(B2bApplication $application, User $applicant): void
    {
        DB::transaction(function () use ($application, $applicant): void {
            $locked = B2bApplication::query()->lockForUpdate()->findOrFail($application->id);
            Gate::forUser($applicant)->authorize('withdrawOwn', $locked);
            $from = $locked->status;

            $locked->forceFill(['status' => 'withdrawn'])->save();
            $this->audit->record(new AuditEntry(
                action: AuditAction::ApplicationWithdrawn,
                actorType: 'user',
                actorUserId: $applicant->id,
                subjectType: 'b2b_application',
                subjectId: $locked->id,
                before: ['status' => $from],
                after: ['status' => 'withdrawn'],
            ));
        });
    }

    /** @return array{0: string, 1: string, 2: string, 3: string, 4: int} disk, path, name, mime, size */
    private function storeEvidence(B2bApplication $application, UploadedFile $file): array
    {
        $disk = (string) config('documents.disk');
        $extension = strtolower($file->extension());
        $path = $file->storeAs("b2b-applications/{$application->public_id}", bin2hex(random_bytes(16)).'.'.$extension, ['disk' => $disk, 'visibility' => 'private']);
        // A safe display name: no path, no control characters.
        $name = mb_substr(preg_replace('/[^\pL\pN ._()-]/u', '_', basename($file->getClientOriginalName())) ?? 'document', 0, 120);

        return [$disk, (string) $path, $name === '' ? "document.{$extension}" : $name, (string) $file->getMimeType(), (int) $file->getSize()];
    }

    private function nextStep(B2bApplication $application): string
    {
        return match ($application->status) {
            'submitted' => 'We will start reviewing your application shortly. You can shop at standard prices meanwhile; trade prices and on-account ordering start once it is approved.',
            'in_review' => 'We are checking your details. We will email you when we decide, or if we need anything else.',
            'info_requested' => 'Please answer the request below. Your application stays open until you do.',
            'approved' => 'Your trade account is open. Choose the company when you next sign in to see trade prices.',
            'rejected' => $application->reapply_after === null || $application->reapply_after->isPast()
                ? 'You may apply again once the reason has been addressed. Contact us if you have questions.'
                : 'You may apply again from '.DisplayTime::format($application->reapply_after, DisplayTime::DATE).'. Contact us if your circumstances change before then.',
            default => 'This application is closed. Contact us if you would like to apply again.',
        };
    }
}
