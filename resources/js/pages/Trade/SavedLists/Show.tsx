/**
 * 05.1 §14.2 — one saved list: its products and quantities, editable by
 * buying roles; Use list is the primary action (it opens the same
 * check-before-adding preview as a paste — never an order). Rename and
 * Delete are secondary, Delete behind a confirmation. Every save names the
 * version it edits: if a colleague saved first, the change is refused and
 * the list reloads.
 */
import { Link, router } from '@inertiajs/react';
import { Loader2, Play, Save, Trash2 } from 'lucide-react';
import { useState } from 'react';

import { ConfirmDialog } from '@/components/trade/ConfirmDialog';
import { PageHeader } from '@/components/trade/PageHeader';
import { EmptyState } from '@/components/trade/states';
import { StatusBadge } from '@/components/trade/StatusBadge';
import { TradeShell } from '@/components/trade/TradeShell';
import { useDisplayTimezone } from '@/components/trade/useDisplayTimezone';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { ApiError } from '@/lib/api/client';
import { deleteSavedList, previewSavedList, updateSavedList, type SavedListDetail } from '@/lib/api/orderTools';
import { formatUkDate } from '@/lib/dateTime';

export default function SavedListShow({ list, can_edit }: { list: SavedListDetail; can_edit: boolean }) {
    const tz = useDisplayTimezone();
    const [name, setName] = useState(list.name);
    const [qty, setQty] = useState<Record<number, string>>(() => Object.fromEntries(list.lines.map((l, i) => [i, String(l.pack_qty)])));
    const [removed, setRemoved] = useState<Set<number>>(new Set());
    const [busy, setBusy] = useState<'save' | 'use' | 'delete' | null>(null);
    const [error, setError] = useState<string | null>(null);
    const [confirmDelete, setConfirmDelete] = useState(false);

    const dirty = name.trim() !== list.name || removed.size > 0 || list.lines.some((l, i) => qty[i] !== String(l.pack_qty));
    const fail = (e: unknown) => {
        setError(e instanceof ApiError ? (e.details[0]?.message ?? e.message) : 'The connection failed. Nothing was changed.');
        if (e instanceof ApiError && e.status === 409) {
            router.reload({ only: ['list'] });
        }
    };

    const save = async () => {
        setBusy('save');
        setError(null);
        try {
            const lines = list.lines
                .map((l, i) => ({ l, i }))
                .filter(({ l, i }) => !removed.has(i) && l.sku_id !== null)
                .map(({ l, i }) => ({ sku_id: l.sku_id as string, pack_code: l.pack_code, pack_qty: Number.parseInt(qty[i] ?? '0', 10) }));
            await updateSavedList(list.id, { version: list.version, name: name.trim(), lines });
            setRemoved(new Set());
            router.reload({ only: ['list'] });
        } catch (e) {
            fail(e);
        } finally {
            setBusy(null);
        }
    };

    const use = async () => {
        setBusy('use');
        setError(null);
        try {
            const result = await previewSavedList(list.id);
            router.visit(result.data.url ?? `/trade/order-tools/imports/${result.data.id}`);
        } catch (e) {
            fail(e);
            setBusy(null);
        }
    };

    const remove = async () => {
        setBusy('delete');
        try {
            await deleteSavedList(list.id, list.version);
            router.visit('/trade/saved-lists');
        } catch (e) {
            fail(e);
            setConfirmDelete(false);
            setBusy(null);
        }
    };

    return (
        <TradeShell title={list.name}>
            <PageHeader
                breadcrumbs={[{ label: 'Saved lists', href: '/trade/saved-lists' }, { label: list.name }]}
                title={list.name}
                description={<p>{list.line_count} products · last changed {formatUkDate(list.updated_at, tz)}{list.created_by && <> · created by {list.created_by}</>}</p>}
                primaryAction={can_edit ? (
                    <Button className="h-11" disabled={busy !== null || dirty} onClick={use} title={dirty ? 'Save your changes first' : undefined}>
                        {busy === 'use' ? <Loader2 className="animate-spin" aria-hidden /> : <Play aria-hidden />} Use list
                    </Button>
                ) : undefined}
                secondaryActions={can_edit ? [{ label: 'Delete list', onSelect: () => setConfirmDelete(true) }] : []}
            />

            {error && <p role="alert" className="mb-4 rounded-lg border border-red-300 bg-red-50 p-3 text-sm text-red-900">{error}</p>}

            {can_edit && (
                <div className="mb-4 flex max-w-md flex-col gap-1.5">
                    <Label htmlFor="list-rename">List name</Label>
                    <Input id="list-rename" value={name} maxLength={80} onChange={(e) => setName(e.target.value)} className="h-11" />
                </div>
            )}

            {list.lines.length === 0 ? (
                <EmptyState title="This list is empty">Save your cart as a new list from the Saved lists page to fill one.</EmptyState>
            ) : (
                <ul className="flex flex-col gap-2" aria-label="Products on this list">
                    {list.lines.map((l, i) => (
                        <li key={`${l.sku_code}-${l.pack_code}`} className={`flex flex-wrap items-center gap-3 rounded-xl border bg-background p-4 ${removed.has(i) ? 'opacity-50' : ''}`}>
                            <div className="min-w-0 flex-1">
                                <p className="font-medium">{l.name}</p>
                                <p className="text-xs text-muted-foreground"><span className="font-mono">{l.sku_code}</span> · {l.pack_label}</p>
                                {(l.sku_status !== 'active' || !l.pack_sellable) && <StatusBadge tone="warning" className="mt-1">No longer available as listed</StatusBadge>}
                            </div>
                            {can_edit ? (
                                <>
                                    <label className="flex items-center gap-2 text-sm">
                                        <span className="sr-only">Packs of {l.name}</span>
                                        <Input type="number" min={1} inputMode="numeric" value={qty[i] ?? ''} disabled={removed.has(i)} onChange={(e) => setQty({ ...qty, [i]: e.target.value })} className="h-11 w-24 text-right" />
                                        packs
                                    </label>
                                    <Button variant="ghost" className="h-11" onClick={() => setRemoved((prev) => { const n = new Set(prev); n.has(i) ? n.delete(i) : n.add(i); return n; })}>
                                        <Trash2 aria-hidden /> {removed.has(i) ? 'Keep' : 'Remove'}
                                    </Button>
                                </>
                            ) : (
                                <p className="text-sm tabular-nums">{l.pack_qty} packs</p>
                            )}
                        </li>
                    ))}
                </ul>
            )}

            {can_edit && dirty && (
                <div className="mt-6 flex gap-2">
                    <Button className="h-11" disabled={busy !== null} onClick={save}>
                        {busy === 'save' ? <Loader2 className="animate-spin" aria-hidden /> : <Save aria-hidden />} Save changes
                    </Button>
                    <Button variant="outline" className="h-11" onClick={() => router.reload()}>Discard</Button>
                </div>
            )}

            {!can_edit && <p className="mt-6 text-sm text-muted-foreground">You can view this list. Ask an owner or buyer on your account to change or use it. <Link href="/order-pad" className="underline">Order pad</Link></p>}

            <ConfirmDialog
                open={confirmDelete}
                onOpenChange={setConfirmDelete}
                title="Delete this list?"
                facts={[{ label: 'List', value: list.name }, { label: 'Products', value: String(list.line_count) }]}
                consequences="It is removed for everyone on your account. Your cart and past orders are not affected."
                confirmLabel="Delete list"
                destructive
                pending={busy === 'delete'}
                onConfirm={remove}
            />
        </TradeShell>
    );
}
