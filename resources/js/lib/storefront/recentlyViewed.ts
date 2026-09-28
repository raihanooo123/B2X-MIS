/**
 * 05.15 §5.3a — "Recently viewed": the last product pages this browser
 * opened, kept in localStorage as a per-viewer convenience and never sent
 * to the server. Storage can be missing or blocked (private windows,
 * cleared site data), so every access is guarded and failure means "none".
 * Only the name, link and picture are kept: prices change and are always
 * shown fresh from the server.
 */
export interface RecentlyViewedItem {
    slug: string;
    name: string;
    thumbnail_url: string | null;
}

const KEY = 'storefront.recently_viewed';
const MAX = 12;

export function readRecentlyViewed(): RecentlyViewedItem[] {
    try {
        const parsed: unknown = JSON.parse(window.localStorage.getItem(KEY) ?? '[]');
        return Array.isArray(parsed)
            ? parsed.filter((i): i is RecentlyViewedItem => typeof i?.slug === 'string' && typeof i?.name === 'string').slice(0, MAX)
            : [];
    } catch {
        return [];
    }
}

export function recordRecentlyViewed(item: RecentlyViewedItem): void {
    try {
        const next = [item, ...readRecentlyViewed().filter((i) => i.slug !== item.slug)].slice(0, MAX);
        window.localStorage.setItem(KEY, JSON.stringify(next));
    } catch {
        // Storage unavailable: nothing to remember, nothing breaks.
    }
}
