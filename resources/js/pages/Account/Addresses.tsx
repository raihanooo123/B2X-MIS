import { router, useForm } from '@inertiajs/react';
import { useState, type FormEvent } from 'react';
import { AccountCard, AccountLayout } from '@/components/account/AccountLayout';
import type { ShellProps } from '@/components/storefront/StorefrontLayout';
import { Button } from '@/components/ui/button';

interface SavedAddress { public_id: string; label: string | null; contact_name: string; phone: string | null; line1: string; line2: string | null; city: string; county: string | null; postcode: string; country_code: string; is_default: boolean }
const empty = { label: '', contact_name: '', phone: '', line1: '', line2: '', city: '', county: '', postcode: '', country_code: 'GB', is_default: false };
const fields = [{ key: 'label', label: 'Label (optional)', max: 191 }, { key: 'contact_name', label: 'Contact name', max: 191, required: true }, { key: 'phone', label: 'Phone (optional)', max: 32 }, { key: 'line1', label: 'Address line 1', max: 191, required: true }, { key: 'line2', label: 'Address line 2 (optional)', max: 191 }, { key: 'city', label: 'Town or city', max: 100, required: true }, { key: 'county', label: 'County (optional)', max: 100 }, { key: 'postcode', label: 'Postcode', max: 16, required: true }] as const;
export default function Addresses({ addresses, shell, status }: { addresses: SavedAddress[]; shell: ShellProps; status: string | null }) {
    const [editing, setEditing] = useState<string | null>(null);
    const [open, setOpen] = useState(false);
    const [busy, setBusy] = useState(false);
    const form = useForm(empty);
    const start = (address?: SavedAddress) => {
        form.clearErrors();
        setEditing(address?.public_id ?? null);
        form.setData(address ? { label: address.label ?? '', contact_name: address.contact_name, phone: address.phone ?? '', line1: address.line1, line2: address.line2 ?? '', city: address.city, county: address.county ?? '', postcode: address.postcode, country_code: 'GB', is_default: address.is_default } : { ...empty });
        setOpen(true);
    };
    const submit = (event: FormEvent) => {
        event.preventDefault();
        const options = { preserveScroll: true, onSuccess: () => { setOpen(false); form.reset(); } };
        if (editing) form.patch(`/account/addresses/${editing}`, options); else form.post('/account/addresses', options);
    };
    const mutate = (address: SavedAddress, action: 'delete' | 'default') => {
        if (busy || (action === 'delete' && !window.confirm('Delete this saved address? Existing orders will keep their delivery details.'))) return;
        setBusy(true);
        const options = { preserveScroll: true, onFinish: () => setBusy(false) };
        if (action === 'delete') router.delete(`/account/addresses/${address.public_id}`, options);
        else router.post(`/account/addresses/${address.public_id}/default`, {}, options);
    };
    return <AccountLayout title="Delivery addresses" shell={shell} status={status} description="Save delivery details for faster checkout." actions={<Button className="min-h-11 md:min-h-9" onClick={() => start()}>Add address</Button>}>
        <div className="space-y-5">
            {addresses.length === 0 && <AccountCard><p className="p-6">No saved delivery addresses. Add your first address to use it at checkout.</p></AccountCard>}
            {addresses.map((address) => <AccountCard key={address.public_id}><div className="p-6">
                <p className="font-semibold">{address.label || address.contact_name}{address.is_default && <span className="ml-2 rounded-full bg-primary/10 px-3 py-1 text-xs font-medium text-primary">Default</span>}</p>
                <p>{address.contact_name}</p><p>{[address.line1, address.line2, address.city, address.county, address.postcode].filter(Boolean).join(', ')}</p>
                {address.phone && <p>{address.phone}</p>}
                <div className="mt-3 flex flex-wrap gap-2"><Button variant="outline" className="min-h-11 md:min-h-9" onClick={() => start(address)}>Edit</Button>{!address.is_default && <Button variant="outline" className="min-h-11 md:min-h-9" disabled={busy} onClick={() => mutate(address, 'default')}>Make default</Button>}<Button variant="outline" className="min-h-11 md:min-h-9" disabled={busy} onClick={() => mutate(address, 'delete')}>Delete</Button></div>
            </div></AccountCard>)}
            {open && <AccountCard title={editing ? 'Edit address' : 'Add address'}><form onSubmit={submit} className="grid gap-5 p-6 sm:grid-cols-2">
                {fields.map((field) => <div key={field.key}><label htmlFor={`saved-${field.key}`} className="block text-sm font-medium">{field.label}</label><input id={`saved-${field.key}`} value={form.data[field.key]} onChange={(e) => form.setData(field.key, e.target.value)} required={'required' in field} maxLength={field.max} className="mt-1 h-11 w-full rounded-md border bg-background px-3" />{form.errors[field.key] && <p className="text-sm text-red-700">{form.errors[field.key]}</p>}</div>)}
                <p className="text-sm sm:col-span-2">Country: United Kingdom (GB)</p>{form.errors.country_code && <p className="text-sm text-red-700 sm:col-span-2">{form.errors.country_code}</p>}
                <label className="flex min-h-11 cursor-pointer items-center gap-2 sm:col-span-2"><input type="checkbox" checked={form.data.is_default} onChange={(e) => form.setData('is_default', e.target.checked)} />Use as default</label>
                <div className="flex gap-2 sm:col-span-2"><Button className="min-h-11 md:min-h-9" disabled={form.processing}>Save address</Button><Button type="button" variant="outline" className="min-h-11 md:min-h-9" onClick={() => setOpen(false)}>Cancel</Button></div>
            </form></AccountCard>}
        </div>
    </AccountLayout>;
}
