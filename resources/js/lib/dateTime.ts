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
