/**
 * 05.1 §7.1–§7.2, §14.2 — paste codes or upload a CSV. Help and the
 * template on the left, the input on the right; Preview is the one
 * primary action. Nothing reaches the basket here: the preview opens the
 * reconciliation screen, where the buyer chooses what to add.
 */
import { Link, router } from '@inertiajs/react';
import { ClipboardPaste, Download, FileUp, Loader2 } from 'lucide-react';
import { useState, type FormEvent } from 'react';

import { PageHeader } from '@/components/trade/PageHeader';
import { TradeShell } from '@/components/trade/TradeShell';
import { Button } from '@/components/ui/button';
import { Label } from '@/components/ui/label';
import { Textarea } from '@/components/ui/textarea';
import { ApiError } from '@/lib/api/client';
import { stageImport } from '@/lib/api/orderTools';
import { cn } from '@/lib/utils';

interface Props {
    limits: { max_rows: number; max_file_mb: number; queued_above: number };
}

export default function OrderToolsImport({ limits }: Props) {
    const [mode, setMode] = useState<'paste' | 'csv'>('paste');
    const [text, setText] = useState('');
    const [file, setFile] = useState<File | null>(null);
    const [busy, setBusy] = useState(false);
    const [error, setError] = useState<string | null>(null);

    const ready = mode === 'paste' ? text.trim() !== '' : file !== null;

    const submit = async (e: FormEvent) => {
        e.preventDefault();
        if (!ready) {
            return;
        }
        setBusy(true);
        setError(null);
        try {
            const result = await stageImport(mode === 'paste' ? { source: 'paste', text } : { source: 'csv', file: file as File });
            router.visit(result.data.url ?? `/trade/order-tools/imports/${result.data.id}`);
        } catch (err) {
            setError(err instanceof ApiError ? (err.details[0]?.message ?? err.message) : 'The connection failed. Nothing was added. Try again.');
            setBusy(false);
        }
    };

    return (
        <TradeShell title="Paste or upload">
            <PageHeader
                breadcrumbs={[{ label: 'Order pad', href: '/order-pad' }, { label: 'Paste or upload' }]}
                title="Paste or upload an order"
                description={<p>Add many products at once. You check every line before anything goes into your cart.</p>}
            />

            <div className="grid gap-6 lg:grid-cols-[20rem_minmax(0,1fr)]">
                <aside className="flex flex-col gap-4 rounded-xl border bg-background p-5 text-sm">
                    <h2 className="text-base font-semibold">How it works</h2>
                    <ul className="list-disc space-y-2 pl-5 text-muted-foreground">
                        <li>One product per line: the code, then the number of packs.</li>
                        <li>Separate them with a comma, semicolon, tab or space.</li>
                        <li>No quantity means one pack of the usual size.</li>
                        <li>Codes are matched whatever their capitals.</li>
                        <li>The same product twice is added together.</li>
                    </ul>
                    <pre className="rounded-lg bg-muted p-3 font-mono text-xs leading-relaxed">{'LTC1179, 3\nLTC1180  12\nLTC1181;5\nLTC1182'}</pre>
                    <div className="border-t pt-4">
                        <p className="mb-2 font-medium">CSV files</p>
                        <p className="text-muted-foreground">
                            Columns <code className="font-mono">sku_code</code>, <code className="font-mono">quantity</code> and, if you need a particular pack, <code className="font-mono">pack_code</code>. Up to {limits.max_file_mb} MB and {limits.max_rows.toLocaleString('en-GB')} rows; over {limits.queued_above} rows are checked in the background.
                        </p>
                        <Button asChild variant="outline" className="mt-3 h-11">
                            <a href="/trade/order-tools/template.csv">
                                <Download aria-hidden /> Download template
                            </a>
                        </Button>
                    </div>
                </aside>

                <form onSubmit={submit} className="flex flex-col gap-4 rounded-xl border bg-background p-5" noValidate>
                    <div role="tablist" aria-label="How to add products" className="flex gap-2 border-b">
                        {(['paste', 'csv'] as const).map((m) => (
                            <button
                                key={m}
                                type="button"
                                role="tab"
                                aria-selected={mode === m}
                                onClick={() => setMode(m)}
                                className={cn('-mb-px inline-flex min-h-11 items-center gap-2 border-b-2 px-3 text-sm font-medium', mode === m ? 'border-primary text-foreground' : 'border-transparent text-muted-foreground hover:text-foreground')}
                            >
                                {m === 'paste' ? <ClipboardPaste className="size-4" aria-hidden /> : <FileUp className="size-4" aria-hidden />}
                                {m === 'paste' ? 'Paste codes' : 'Upload CSV'}
                            </button>
                        ))}
                    </div>

                    {mode === 'paste' ? (
                        <div className="flex flex-col gap-1.5">
                            <Label htmlFor="paste-input">Codes and quantities</Label>
                            <Textarea id="paste-input" rows={14} value={text} onChange={(e) => setText(e.target.value)} className="font-mono text-sm" placeholder={'LTC1179, 3\nLTC1180 12'} aria-describedby="paste-hint" />
                            <p id="paste-hint" className="text-xs text-muted-foreground">
                                {text.split(/\r?\n/).filter((l) => l.trim() !== '').length} lines
                            </p>
                        </div>
                    ) : (
                        <div className="flex flex-col gap-1.5">
                            <Label htmlFor="csv-input">CSV file</Label>
                            <label htmlFor="csv-input" className="flex min-h-40 cursor-pointer flex-col items-center justify-center gap-2 rounded-xl border-2 border-dashed bg-muted/30 p-6 text-center text-sm hover:bg-muted/50">
                                <FileUp className="size-8 text-muted-foreground" aria-hidden />
                                <span className="font-medium">{file ? file.name : 'Choose a CSV file'}</span>
                                <span className="text-xs text-muted-foreground">UTF-8 CSV, up to {limits.max_file_mb} MB</span>
                            </label>
                            <input id="csv-input" type="file" accept=".csv,text/csv" className="sr-only" onChange={(e) => setFile(e.target.files?.[0] ?? null)} />
                        </div>
                    )}

                    {error && (
                        <p role="alert" className="rounded-lg border border-red-300 bg-red-50 p-3 text-sm text-red-900">
                            {error}
                        </p>
                    )}

                    <div className="flex flex-wrap items-center gap-3">
                        <Button type="submit" className="h-11" disabled={!ready || busy}>
                            {busy && <Loader2 className="animate-spin" aria-hidden />} Preview
                        </Button>
                        <Link href="/order-pad" className="inline-flex min-h-11 items-center text-sm text-muted-foreground underline-offset-4 hover:underline">
                            Back to the order pad
                        </Link>
                    </div>
                </form>
            </div>
        </TradeShell>
    );
}
