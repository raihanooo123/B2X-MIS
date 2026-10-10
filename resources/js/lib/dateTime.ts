/**
 * Times arrive as UTC ISO strings and are shown in the one display
 * timezone the server shares with every page (`display_timezone`,
 * App\Support\DisplayTime) — never the browser's own zone, so a customer
 * abroad sees the same time as the admin and their emails.
 */
export const FALLBACK_DISPLAY_TIMEZONE = 'Europe/London';

export function formatDateTime(iso: string | null, timeZone: string = FALLBACK_DISPLAY_TIMEZONE): string {
    return iso === null ? '' : new Intl.DateTimeFormat('en-GB', { dateStyle: 'long', timeStyle: 'short', timeZone }).format(new Date(iso));
}

/**
 * 05.16 §5: a UK calendar date, `05/10/2026`, in the display timezone.
 * API timestamps stay UTC ISO 8601; only display converts.
 */
export function formatUkDate(iso: string | null, timeZone: string = FALLBACK_DISPLAY_TIMEZONE): string {
    return iso === null ? '' : new Intl.DateTimeFormat('en-GB', { day: '2-digit', month: '2-digit', year: 'numeric', timeZone }).format(new Date(iso));
}

/**
 * `05/10/2026, 14:30` in the display timezone; with `withZone`, the zone
 * the reader needs where the time is consequential (an expiry, a payment
 * deadline): `05/10/2026, 14:30 BST`.
 */
export function formatUkDateTime(iso: string | null, timeZone: string = FALLBACK_DISPLAY_TIMEZONE, withZone = false): string {
    if (iso === null) {
        return '';
    }
    const parts = new Intl.DateTimeFormat('en-GB', {
        day: '2-digit',
        month: '2-digit',
        year: 'numeric',
        hour: '2-digit',
        minute: '2-digit',
        hourCycle: 'h23',
        timeZone,
        ...(withZone ? { timeZoneName: 'short' as const } : {}),
    }).formatToParts(new Date(iso));
    const part = (type: Intl.DateTimeFormatPartTypes): string => parts.find((p) => p.type === type)?.value ?? '';
    const zone = withZone ? ` ${part('timeZoneName')}` : '';

    return `${part('day')}/${part('month')}/${part('year')}, ${part('hour')}:${part('minute')}${zone}`;
}
