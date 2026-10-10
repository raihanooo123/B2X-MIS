import { usePage } from '@inertiajs/react';

import { FALLBACK_DISPLAY_TIMEZONE } from '@/lib/dateTime';
import type { SharedProps } from '@/types/shared';

/** The one zone every page shows times in (App\Support\DisplayTime). */
export function useDisplayTimezone(): string {
    return usePage<SharedProps>().props.display_timezone ?? FALLBACK_DISPLAY_TIMEZONE;
}
