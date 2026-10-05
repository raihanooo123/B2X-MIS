/**
 * 05.11 §2 — a legal or help page (Privacy, Cookies, Delivery, Returns &
 * cancellations, Contact) or the terms of sale: the published version in
 * force, rendered on the server from Markdown with raw HTML escaped, plus
 * the block the admin cannot edit — the cookie table, the statutory
 * cancellation and returns statement (the same text as checkout and the
 * confirmation email), or the contact and legal details.
 */
import { usePage } from '@inertiajs/react';
import { Mail, MapPin, Phone } from 'lucide-react';

import { Breadcrumb } from '@/components/storefront/Breadcrumb';
import { StorefrontLayout, type ShellProps } from '@/components/storefront/StorefrontLayout';
import type { SharedProps } from '@/types/shared';

interface CookieEntry {
    name: string;
    set_by: string;
    purpose: string;
    lifetime: string;
    category: string;
}

type Block =
    | { type: 'cookies'; cookies: CookieEntry[] }
    | { type: 'statutory'; sections: { heading: string; paragraphs: string[] }[] }
    | { type: 'contact' };

interface LegalPageProps {
    shell: ShellProps;
    page: { key: string; title: string; html: string; version: string; effective_from: string };
    block: Block | null;
}

export default function LegalPage({ shell, page, block }: LegalPageProps) {
    const { display_timezone: timeZone } = usePage<SharedProps>().props;
    const effective = new Intl.DateTimeFormat('en-GB', { dateStyle: 'long', timeZone }).format(new Date(page.effective_from));

    return (
        <StorefrontLayout title={page.title} shell={shell}>
            <div className="mx-auto max-w-3xl px-4 pb-6 pt-2 md:pt-6">
                <Breadcrumb trail={[]} current={page.title} />
                <h1 className="mt-2 text-[1.75rem] font-extrabold leading-tight tracking-tight sm:text-4xl">{page.title}</h1>
                <p className="mt-2 text-sm text-muted-foreground">
                    Version {page.version}, in effect from {effective}
                </p>

                {/* Server-rendered Markdown: raw HTML escaped, unsafe links refused (LegalPageController). */}
                <article
                    className="mt-8 space-y-4 leading-relaxed text-foreground/90 [&_a]:font-medium [&_a]:text-primary [&_a]:underline [&_a]:underline-offset-4 [&_h1]:text-2xl [&_h1]:font-bold [&_h2]:mt-8 [&_h2]:text-xl [&_h2]:font-bold [&_h3]:mt-6 [&_h3]:font-semibold [&_li]:mt-1 [&_ol]:list-decimal [&_ol]:pl-6 [&_strong]:font-semibold [&_ul]:list-disc [&_ul]:pl-6"
                    dangerouslySetInnerHTML={{ __html: page.html }}
                />

                {block?.type === 'cookies' && <CookieTable cookies={block.cookies} />}
                {block?.type === 'statutory' && <Statutory sections={block.sections} />}
                {block?.type === 'contact' && <ContactDetails />}
            </div>
        </StorefrontLayout>
    );
}

function CookieTable({ cookies }: { cookies: CookieEntry[] }) {
    return (
        <section aria-labelledby="cookie-list" className="mt-10">
            <h2 id="cookie-list" className="text-xl font-bold">
                Cookies we use
            </h2>
            <p className="mt-2 text-sm text-muted-foreground">
                All of these are strictly necessary for the site to work or to keep it secure, so they don't need your consent.
            </p>
            {/* A card per cookie on small screens; the table needs room. */}
            <ul className="mt-4 space-y-3 md:hidden">
                {cookies.map((cookie) => (
                    <li key={cookie.name} className="rounded-2xl border p-4 text-sm">
                        <p className="font-mono font-semibold">{cookie.name}</p>
                        <p className="mt-1">{cookie.purpose}</p>
                        <p className="mt-2 text-muted-foreground">
                            {cookie.set_by} · {cookie.lifetime}
                        </p>
                    </li>
                ))}
            </ul>
            <div className="mt-4 hidden overflow-hidden rounded-2xl border md:block">
                <table className="w-full text-left text-sm">
                    <thead className="bg-muted/50 text-muted-foreground">
                        <tr>
                            <th scope="col" className="px-4 py-3 font-medium">Name</th>
                            <th scope="col" className="px-4 py-3 font-medium">Purpose</th>
                            <th scope="col" className="px-4 py-3 font-medium">Set by</th>
                            <th scope="col" className="px-4 py-3 font-medium">Lasts</th>
                        </tr>
                    </thead>
                    <tbody className="divide-y">
                        {cookies.map((cookie) => (
                            <tr key={cookie.name} className="align-top">
                                <td className="px-4 py-3 font-mono font-semibold">{cookie.name}</td>
                                <td className="px-4 py-3">{cookie.purpose}</td>
                                <td className="px-4 py-3 text-muted-foreground">{cookie.set_by}</td>
                                <td className="px-4 py-3 text-muted-foreground">{cookie.lifetime}</td>
                            </tr>
                        ))}
                    </tbody>
                </table>
            </div>
        </section>
    );
}

function Statutory({ sections }: { sections: { heading: string; paragraphs: string[] }[] }) {
    return (
        <section aria-labelledby="your-rights" className="mt-10 rounded-3xl bg-muted/40 p-6 sm:p-8">
            <h2 id="your-rights" className="text-xl font-bold">
                Your rights in brief
            </h2>
            <p className="mt-1 text-sm text-muted-foreground">The same statement you see at checkout and in your order confirmation.</p>
            <div className="mt-4 space-y-5">
                {sections.map((section) => (
                    <div key={section.heading}>
                        <h3 className="font-semibold">{section.heading}</h3>
                        {section.paragraphs.map((paragraph) => (
                            <p key={paragraph} className="mt-1.5 leading-relaxed text-foreground/90">
                                {paragraph}
                            </p>
                        ))}
                    </div>
                ))}
            </div>
        </section>
    );
}

function ContactDetails() {
    const { brand } = usePage<SharedProps>().props;
    const { legal } = brand;

    return (
        <section aria-labelledby="contact-details" className="mt-10 grid gap-4 sm:grid-cols-2">
            <h2 id="contact-details" className="sr-only">
                Contact details
            </h2>
            {(brand.support_email || brand.support_phone) && (
                <div className="rounded-3xl bg-muted/40 p-6">
                    <p className="font-semibold">Get in touch</p>
                    <ul className="mt-3 space-y-1">
                        {brand.support_phone && (
                            <li>
                                <a href={`tel:${brand.support_phone.replace(/[^+\d]/g, '')}`} className="inline-flex min-h-11 items-center gap-2 hover:text-primary">
                                    <Phone className="size-4 text-muted-foreground" aria-hidden /> {brand.support_phone}
                                </a>
                            </li>
                        )}
                        {brand.support_email && (
                            <li>
                                <a href={`mailto:${brand.support_email}`} className="inline-flex min-h-11 items-center gap-2 break-all hover:text-primary">
                                    <Mail className="size-4 shrink-0 text-muted-foreground" aria-hidden /> {brand.support_email}
                                </a>
                            </li>
                        )}
                    </ul>
                </div>
            )}
            {(legal.name || legal.address.length > 0) && (
                <div className="rounded-3xl bg-muted/40 p-6">
                    <p className="flex items-center gap-2 font-semibold">
                        <MapPin className="size-4 text-muted-foreground" aria-hidden /> Registered details
                    </p>
                    <address className="mt-3 space-y-0.5 not-italic text-foreground/90">
                        {legal.name && <p>{legal.name}</p>}
                        {legal.address.map((line) => (
                            <p key={line}>{line}</p>
                        ))}
                        {legal.company_number && <p className="pt-2">Company no. {legal.company_number}</p>}
                        {legal.vat_number && <p>VAT no. {legal.vat_number}</p>}
                    </address>
                </div>
            )}
        </section>
    );
}
