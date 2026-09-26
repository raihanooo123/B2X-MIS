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
    staff_navigation: {
        admin: boolean;
        goods_in: boolean;
        picking: boolean;
        dispatch: boolean;
        stocktake: boolean;
    };
}

export interface SharedProps {
    auth: SharedAuth | null;
    flash: { status: string | null };
    [key: string]: unknown;
}
