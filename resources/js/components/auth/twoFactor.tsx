/**
 * Pieces shared by 2FA enrolment (Auth/TwoFactorSetup) and the account
 * page (Account/Index): recovery-code display, a copy button that works
 * outside secure contexts, and the "I have saved these" confirmation that
 * must be ticked before codes take effect (05.13 §12.2).
 */
import { useForm } from '@inertiajs/react';
import { AlertTriangle, Check, Copy, Download } from 'lucide-react';
import { useState, type FormEvent, type ReactNode } from 'react';

import { Checkbox } from '@/components/auth/Field';
import { Button } from '@/components/ui/button';

/**
 * Copies text. The Clipboard API exists only in a secure context (HTTPS or
 * localhost) — on a LAN address or an http:// dev host it is undefined and
 * the old button did nothing at all. Falls back to a selected, hidden
 * textarea and execCommand('copy'), which still works there.
 */
export async function copyText(text: string): Promise<boolean> {
    try {
        if (window.isSecureContext && navigator.clipboard) {
            await navigator.clipboard.writeText(text);

            return true;
        }
    } catch {
        // fall through to the legacy path
    }

    const area = document.createElement('textarea');
    area.value = text;
    area.setAttribute('readonly', '');
    area.style.position = 'fixed';
    area.style.opacity = '0';
    document.body.appendChild(area);
    area.select();
    let copied = false;
    try {
        copied = document.execCommand('copy');
    } catch {
        copied = false;
    }
    area.remove();

    return copied;
}

export function CopyButton({ text, label, onCopied }: { text: string; label: string; onCopied?: () => void }) {
    const [state, setState] = useState<'idle' | 'copied' | 'failed'>('idle');

    return (
        <Button
            type="button"
            variant="outline"
            className="h-11 md:h-9"
            disabled={text === ''}
            onClick={async () => {
                const ok = await copyText(text);
                setState(ok ? 'copied' : 'failed');
                if (ok) {
                    onCopied?.();
                }
                window.setTimeout(() => setState('idle'), 2500);
            }}
        >
            {state === 'copied' ? <Check /> : state === 'failed' ? <AlertTriangle /> : <Copy />}
            {state === 'copied' ? 'Copied' : state === 'failed' ? 'Copy failed — select it instead' : label}
        </Button>
    );
}

export function CodeList({ codes }: { codes: string[] }) {
    const text = codes.join('\n');

    const download = () => {
        const url = URL.createObjectURL(new Blob([`B2X Wholesale recovery codes\n\n${text}\n`], { type: 'text/plain' }));
        const a = document.createElement('a');
        a.href = url;
        a.download = 'b2x-recovery-codes.txt';
        a.click();
        URL.revokeObjectURL(url);
    };

    return (
        <div className="space-y-3">
            <ul className="grid grid-cols-2 gap-2 rounded-md border bg-muted p-3 font-mono text-sm" aria-label="Recovery codes">
                {codes.map((c) => (
                    <li key={c} className="select-all">
                        {c}
                    </li>
                ))}
            </ul>
            <div className="flex flex-wrap gap-2">
                <CopyButton text={text} label="Copy codes" />
                <Button type="button" variant="outline" className="h-11 md:h-9" onClick={download}>
                    <Download /> Download
                </Button>
            </div>
        </div>
    );
}

/**
 * Recovery codes shown for saving, and the tick that must be given before
 * they take effect. The submit button stays disabled until it is ticked;
 * the server refuses without it too (`saved` must be accepted).
 */
export function SaveRecoveryCodes({ codes, action, submitLabel, intro, secondary }: { codes: string[]; action: string; submitLabel: string; intro: ReactNode; secondary?: ReactNode }) {
    const form = useForm({ saved: false });

    const submit = (e: FormEvent) => {
        e.preventDefault();
        form.post(action);
    };

    return (
        <form onSubmit={submit} className="space-y-4" noValidate>
            <div className="text-sm text-muted-foreground">{intro}</div>
            <CodeList codes={codes} />
            <div className="rounded-md border border-amber-300 bg-amber-50 p-3">
                <Checkbox
                    label={<span className="font-medium text-amber-950">I have saved these recovery codes somewhere safe, away from my phone.</span>}
                    checked={form.data.saved}
                    onChange={(v) => form.setData('saved', v)}
                    error={form.errors.saved}
                />
            </div>
            <div className="flex flex-col gap-2 sm:flex-row">
                <Button type="submit" className="h-11 flex-1" disabled={!form.data.saved || form.processing}>
                    {submitLabel}
                </Button>
                {secondary}
            </div>
        </form>
    );
}
