<?php

namespace App\Domain\Returns;

use App\Domain\Audit\AuditAction;
use App\Domain\Audit\AuditEntry;
use App\Domain\Audit\AuditLogger;
use App\Domain\Notifications\Notifications;
use App\Domain\Returns\Exceptions\ReturnActionRefusedException;
use App\Models\Attachment;
use App\Models\Rma;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

/**
 * 05.4 §13.5 — a consumer's proof of sending the goods back (proof of
 * postage, a tracking record), as amended 2026-10-04:
 *
 *   - `goods_sent_at` is the moment the customer uploads it (reg.
 *     34(5)(b)), and the refund deadline runs from it;
 *   - a second upload while proof stands keeps the earlier time;
 *   - staff never confirm it into place — accepting it is no action — but
 *     may reject invalid proof: `goods_sent_at` is cleared and the deadline
 *     recomputed, the rejection is audited (`rma.proof_rejected`: who, when,
 *     why, and the last proof file it covers), and the customer is told and
 *     may upload again. **The files are kept**: they are evidence if the
 *     refund is disputed. A file up to the latest rejection's last file is
 *     shown to staff as rejected (ReturnResource), with no column of its own.
 *
 * Only while the return is awaiting the goods and the customer is posting
 * it back (not when we collect). The file is stored on the default
 * (private) disk, never served publicly.
 */
final class ProofOfSending
{
    public const MAX_KILOBYTES = 10240;

    /** @var list<string> */
    public const MIMES = ['jpg', 'jpeg', 'png', 'webp', 'pdf'];

    public function __construct(
        private readonly Notifications $notifications = new Notifications,
    ) {}

    public function upload(int $rmaId, UploadedFile $file, ?int $uploadedByUserId): Rma
    {
        $disk = (string) config('filesystems.default');
        $path = $file->storeAs('rma-proof', Str::ulid().'.'.strtolower($file->getClientOriginalExtension() ?: 'bin'), $disk);
        if ($path === false) {
            throw new ReturnActionRefusedException('upload_failed', 'Your file could not be saved. Please try again.');
        }

        try {
            return DB::transaction(function () use ($rmaId, $file, $uploadedByUserId, $disk, $path): Rma {
                $rma = Rma::query()->lockForUpdate()->findOrFail($rmaId);
                $this->assertAcceptsProof($rma);

                Attachment::query()->create([
                    'attachable_type' => 'rma',
                    'attachable_id' => $rma->id,
                    'disk' => $disk,
                    'path' => $path,
                    'original_name' => mb_substr($file->getClientOriginalName(), 0, 255),
                    'mime_type' => (string) ($file->getMimeType() ?? 'application/octet-stream'),
                    'size_bytes' => (int) $file->getSize(),
                    'is_customer_visible' => true,
                    'uploaded_by_user_id' => $uploadedByUserId,
                ]);

                // The earliest proof stands: more evidence never makes the deadline later.
                if ($rma->goods_sent_at === null) {
                    $rma->goods_sent_at = now();
                }
                RefundDeadline::apply($rma);
                $rma->save();

                return $rma;
            });
        } catch (\Throwable $e) {
            Storage::disk($disk)->delete($path);
            throw $e;
        }
    }

    public function reject(int $rmaId, int $staffUserId, string $reason): Rma
    {
        return DB::transaction(function () use ($rmaId, $staffUserId, $reason): Rma {
            $rma = Rma::query()->lockForUpdate()->findOrFail($rmaId);
            if ($rma->goods_sent_at === null) {
                throw new ReturnActionRefusedException('no_proof', 'There is no proof of sending to reject.');
            }
            if ($rma->status !== 'awaiting_goods') {
                throw new ReturnActionRefusedException('already_received', 'The goods have arrived, so the proof no longer matters.');
            }

            $lastFile = (int) Attachment::query()->where('attachable_type', 'rma')->where('attachable_id', $rma->id)->max('id');

            (new AuditLogger)->record(new AuditEntry(
                action: AuditAction::RmaProofRejected,
                actorType: 'user',
                actorUserId: $staffUserId,
                subjectType: 'rma',
                subjectId: $rma->id,
                before: ['goods_sent_at' => $rma->goods_sent_at->toIso8601String(), 'last_proof_attachment_id' => $lastFile],
                after: ['goods_sent_at' => null],
                reason: $reason,
            ));

            $rma->goods_sent_at = null;
            $rma->handled_by_user_id = $staffUserId;
            RefundDeadline::apply($rma);
            $rma->save();

            $this->notifications->rmaProofRejected($rma->id, $reason);

            return $rma;
        });
    }

    private function assertAcceptsProof(Rma $rma): void
    {
        if ($rma->company_id !== null || $rma->return_reason !== 'consumer_cancellation') {
            throw new ReturnActionRefusedException('not_consumer_cancellation', 'Proof of sending is only needed for a cancellation you post back.');
        }
        if ($rma->return_method === 'collection') {
            throw new ReturnActionRefusedException('we_collect', 'We are collecting these goods, so you do not need to send proof.');
        }
        if ($rma->status !== 'awaiting_goods') {
            throw new ReturnActionRefusedException('not_awaiting_goods', 'We already have these goods back, so no proof is needed.');
        }
    }
}
