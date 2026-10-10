/**
 * 05.17 §4 — the applicant's own trade application, on the storefront
 * shell (no company yet, so no trade shell and no financial data): status
 * with dates and the next step; the information request and a reply form
 * with documents; withdrawal behind a confirmation; and, if not approved,
 * the message we sent and when they may apply again. Never the reviewers'
 * internal reason or the verification evidence.
 */
import { Link, useForm } from '@inertiajs/react';
import { CheckCircle2, Clock, FileQuestion, Loader2, XCircle, type LucideIcon } from 'lucide-react';
import { useState, type FormEvent } from 'react';

import { StorefrontLayout, type ShellProps } from '@/components/storefront/StorefrontLayout';
import { ConfirmDialog } from '@/components/trade/ConfirmDialog';
import { StatusBadge, type StatusTone } from '@/components/trade/StatusBadge';
import { useDisplayTimezone } from '@/components/trade/useDisplayTimezone';
import { Button } from '@/components/ui/button';
import { Label } from '@/components/ui/label';
import { Textarea } from '@/components/ui/textarea';
import { formatUkDate } from '@/lib/dateTime';

interface Application {
    id: string;
    company_name: string;
    status: string;
    status_label: string;
    submitted_at: string;
    reviewed_at: string | null;
    info_request: string | null;
    applicant_message: string | null;
    rejection: { remediable: boolean; reapply_after: string | null; reapply_after_display: string | null; may_reapply_now: boolean } | null;
    next_step: string;
    can_reply: boolean;
    can_withdraw: boolean;
    history: { id: string; company_name: string; status: string; status_label: string; submitted_at: string }[];
}

interface Props {
    shell: ShellProps;
    application: Application | null;
    limits: { reply_max: number; files_max: number; file_max_mb: number };
}

const TONE: Record<string, { tone: StatusTone; icon: LucideIcon }> = {
    submitted: { tone: 'info', icon: Clock },
    in_review: { tone: 'pending', icon: Clock },
    info_requested: { tone: 'warning', icon: FileQuestion },
    approved: { tone: 'success', icon: CheckCircle2 },
    rejected: { tone: 'danger', icon: XCircle },
    withdrawn: { tone: 'neutral', icon: XCircle },
};

function ReplyForm({ limits }: { limits: Props['limits'] }) {
    const form = useForm<{ message: string; files: File[] }>({ message: '', files: [] });

    const submit = (e: FormEvent) => {
        e.preventDefault();
        form.post('/trade/application/reply', { forceFormData: true, preserveScroll: true, onSuccess: () => form.reset() });
    };
    const fileErrors = Object.entries(form.errors).filter(([key]) => key.startsWith('files'));

    return (
        <form onSubmit={submit} className="flex flex-col gap-4" noValidate>
            <div className="flex flex-col gap-1.5">
                <Label htmlFor="reply-message">Your answer</Label>
                <Textarea
                    id="reply-message"
                    rows={6}
                    maxLength={limits.reply_max}
                    value={form.data.message}
                    onChange={(e) => form.setData('message', e.target.value)}
                    aria-invalid={form.errors.message !== undefined}
                    aria-describedby="reply-message-hint"
                />
                <p id="reply-message-hint" className="text-xs text-muted-foreground">
                    {form.data.message.length} of {limits.reply_max} characters.
                </p>
                {form.errors.message && <p className="text-sm text-red-800">{form.errors.message}</p>}
            </div>
            <div className="flex flex-col gap-1.5">
                <Label htmlFor="reply-files">Documents (optional)</Label>
                <input
                    id="reply-files"
                    type="file"
                    multiple
                    accept=".pdf,.jpg,.jpeg,.png,application/pdf,image/jpeg,image/png"
                    onChange={(e) => form.setData('files', Array.from(e.target.files ?? []).slice(0, limits.files_max))}
                    className="min-h-11 text-sm file:mr-3 file:h-11 file:rounded-md file:border file:bg-background file:px-3"
                    aria-describedby="reply-files-hint"
                />
                <p id="reply-files-hint" className="text-xs text-muted-foreground">
                    Up to {limits.files_max} files: PDF, JPEG or PNG, {limits.file_max_mb} MB each.
                </p>
                {fileErrors.map(([key, message]) => (
                    <p key={key} className="text-sm text-red-800">
                        {message}
                    </p>
                ))}
            </div>
            <Button type="submit" className="h-11 self-start" disabled={form.processing || form.data.message.trim() === ''}>
                {form.processing && <Loader2 className="animate-spin" aria-hidden />} Send reply
            </Button>
        </form>
    );
}

export default function ApplicationStatus({ shell, application, limits }: Props) {
    const tz = useDisplayTimezone();
    const [confirming, setConfirming] = useState(false);
    const withdraw = useForm({ confirm: true });

    if (application === null) {
        return (
            <StorefrontLayout title="Trade application" shell={shell}>
                <div className="mx-auto max-w-2xl px-4 py-10">
                    <h1 className="text-2xl font-semibold">Trade application</h1>
                    <p className="mt-3 text-muted-foreground">You have no trade account application.</p>
                </div>
            </StorefrontLayout>
        );
    }

    const tone = TONE[application.status] ?? { tone: 'neutral' as StatusTone, icon: Clock };

    return (
        <StorefrontLayout title="Trade application" shell={shell}>
            <div className="mx-auto flex max-w-2xl flex-col gap-6 px-4 py-10">
                <header>
                    <p className="text-sm text-muted-foreground">Trade account application</p>
                    <h1 className="mt-1 text-2xl font-semibold">{application.company_name}</h1>
                </header>

                <section aria-labelledby="status-heading" className="rounded-xl border bg-background p-5">
                    <div className="flex flex-wrap items-center justify-between gap-3">
                        <h2 id="status-heading" className="text-lg font-semibold">
                            Status
                        </h2>
                        <StatusBadge tone={tone.tone}>{application.status_label}</StatusBadge>
                    </div>
                    <dl className="mt-3 grid gap-2 text-sm sm:grid-cols-2">
                        <div>
                            <dt className="text-muted-foreground">Sent</dt>
                            <dd className="tabular-nums">{formatUkDate(application.submitted_at, tz)}</dd>
                        </div>
                        {application.reviewed_at && (
                            <div>
                                <dt className="text-muted-foreground">Decided</dt>
                                <dd className="tabular-nums">{formatUkDate(application.reviewed_at, tz)}</dd>
                            </div>
                        )}
                    </dl>
                    <p className="mt-4 rounded-lg bg-muted/60 p-3 text-sm">
                        <span className="font-medium">What happens next: </span>
                        {application.next_step}
                    </p>
                    {application.status === 'approved' && (
                        <Button asChild className="mt-4 h-11">
                            <Link href="/choose-company">Choose your company</Link>
                        </Button>
                    )}
                </section>

                {application.info_request && (
                    <section aria-labelledby="request-heading" className="rounded-xl border border-amber-300 bg-amber-50/60 p-5">
                        <h2 id="request-heading" className="text-lg font-semibold">
                            We need more information
                        </h2>
                        <p className="mt-2 whitespace-pre-line text-sm">{application.info_request}</p>
                        {application.can_reply && (
                            <div className="mt-5 rounded-lg border bg-background p-4">
                                <ReplyForm limits={limits} />
                            </div>
                        )}
                    </section>
                )}

                {application.status === 'rejected' && application.rejection && (
                    <section aria-labelledby="decision-heading" className="rounded-xl border bg-background p-5">
                        <h2 id="decision-heading" className="text-lg font-semibold">
                            Our decision
                        </h2>
                        {application.applicant_message && <p className="mt-2 whitespace-pre-line text-sm">{application.applicant_message}</p>}
                        <p className="mt-3 text-sm">
                            {application.rejection.may_reapply_now
                                ? 'You may apply again.'
                                : `You may apply again from ${application.rejection.reapply_after_display}.`}{' '}
                            You can keep buying at standard prices meanwhile.{' '}
                            <Link href="/contact" className="underline underline-offset-4">
                                Contact us
                            </Link>{' '}
                            with any questions.
                        </p>
                    </section>
                )}

                {application.can_withdraw && (
                    <section aria-labelledby="withdraw-heading" className="rounded-xl border bg-background p-5">
                        <h2 id="withdraw-heading" className="text-base font-semibold">
                            Withdraw the application
                        </h2>
                        <p className="mt-1 text-sm text-muted-foreground">If you no longer need a trade account, you can withdraw. You will keep your login and can still shop at standard prices.</p>
                        <Button variant="outline" className="mt-3 h-11" onClick={() => setConfirming(true)}>
                            Withdraw application
                        </Button>
                        <ConfirmDialog
                            open={confirming}
                            onOpenChange={setConfirming}
                            title="Withdraw this application?"
                            facts={[{ label: 'Company', value: application.company_name }]}
                            consequences="We stop reviewing it. To get trade prices later you will need to apply again."
                            confirmLabel="Withdraw application"
                            destructive
                            pending={withdraw.processing}
                            error={withdraw.errors.confirm ?? null}
                            onConfirm={() => withdraw.post('/trade/application/withdraw', { preserveScroll: true, onSuccess: () => setConfirming(false) })}
                        />
                    </section>
                )}

                {application.history.length > 0 && (
                    <section aria-labelledby="history-heading">
                        <h2 id="history-heading" className="mb-2 text-base font-semibold">
                            Earlier applications
                        </h2>
                        <ul className="divide-y rounded-xl border bg-background text-sm">
                            {application.history.map((h) => (
                                <li key={h.id} className="flex flex-wrap justify-between gap-2 px-4 py-3">
                                    <span>
                                        {h.company_name} <span className="text-muted-foreground">· {formatUkDate(h.submitted_at, tz)}</span>
                                    </span>
                                    <StatusBadge tone={(TONE[h.status] ?? { tone: 'neutral' }).tone}>{h.status_label}</StatusBadge>
                                </li>
                            ))}
                        </ul>
                    </section>
                )}
            </div>
        </StorefrontLayout>
    );
}
