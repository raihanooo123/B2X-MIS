import { useEffect, useState } from 'react';

/** Tailwind's `md`, the table breakpoint (05.1 §8.2). */
export const DESKTOP_QUERY = '(min-width: 768px)';

/** Tracks a media query; pages render one layout at a time (table or cards). */
export function useMediaQuery(query: string): boolean {
    const [matches, setMatches] = useState(() => window.matchMedia(query).matches);

    useEffect(() => {
        const list = window.matchMedia(query);
        const onChange = () => setMatches(list.matches);
        onChange();
        list.addEventListener('change', onChange);

        return () => list.removeEventListener('change', onChange);
    }, [query]);

    return matches;
}
