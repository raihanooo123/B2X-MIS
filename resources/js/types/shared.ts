/**
 * Props every page receives from HandleInertiaRequests::share().
 */
export interface SharedAuth {
    user: {
        first_name: string;
        last_name: string;
        email: string;
        email_verified: boolean;
        two_factor_enabled: boolean;
    };
    /** The company being acted for (05.13 §6.3); null for public customers and applicants. */
    company: { id: string; name: string } | null;
    can_switch_company: boolean;
    public_customer: boolean;
    /** An owner of the acting company: shows the Team link. */
    can_manage_team: boolean;
    staff_navigation: {
        admin: boolean;
        goods_in: boolean;
        picking: boolean;
        dispatch: boolean;
        stocktake: boolean;
        returns: boolean;
    };
}

/** The business's own branding (05.15 §3.1, App\Domain\Storefront\Branding). */
export interface SharedBrand {
    name: string;
    tagline: string | null;
    logo_url: string | null;
    /** HSL channels for the `--primary` token, e.g. "224 76% 48%"; null keeps the default. */
    primary_hsl: string | null;
    primary_foreground_hsl: string | null;
    support_email: string | null;
    support_phone: string | null;
    show_powered_by: boolean;
    /** The seller's legal details, required on a company's website. */
    legal: {
        name: string | null;
        address: string[];
        company_number: string | null;
        vat_number: string | null;
    };
}

export interface SharedProps {
    auth: SharedAuth | null;
    flash: { status: string | null };
    /** IANA zone every time is shown in (App\Support\DisplayTime); storage is UTC. */
    display_timezone: string;
    brand: SharedBrand;
    /** Inc or ex VAT (App\Http\Support\PriceDisplay); only guests and public customers may switch. */
    price_display: { mode: 'net' | 'gross'; can_switch: boolean };
    [key: string]: unknown;
}
