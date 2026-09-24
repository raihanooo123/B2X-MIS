/**
 * Minimal fetch wrapper for the first-party /api/v1 surface (doc 06).
 *
 * Auth is the session cookie plus CSRF (06 §1). The CSRF token is read
 * from Laravel's `XSRF-TOKEN` cookie on every request and sent back as
 * `X-XSRF-TOKEN` — the cookie, not a `<meta>` tag, because Laravel
 * refreshes it on every response, so it stays valid after the session is
 * regenerated at login, where a token rendered into the page would go
 * stale in a long-lived SPA session.
 *
 * Failures are thrown as ApiError, carrying 06 §4's envelope, so callers
 * branch on `error.code` and render `details[]` per field — never on
 * `message`, which may change.
 */

export interface ApiErrorDetail {
    field: string | null;
    code: string;
    message: string;
    meta?: Record<string, unknown>;
}

export interface ApiErrorEnvelope {
    error: {
        code: string;
        message: string;
        details: ApiErrorDetail[];
        request_id: string;
    };
}

export class ApiError extends Error {
    readonly status: number;
    readonly code: string;
    readonly details: ApiErrorDetail[];
    readonly requestId: string | null;

    constructor(status: number, code: string, message: string, details: ApiErrorDetail[] = [], requestId: string | null = null) {
        super(message);
        this.name = 'ApiError';
        this.status = status;
        this.code = code;
        this.details = details;
        this.requestId = requestId;
    }
}

function xsrfToken(): string | null {
    const match = document.cookie.split('; ').find((c) => c.startsWith('XSRF-TOKEN='));

    return match ? decodeURIComponent(match.slice('XSRF-TOKEN='.length)) : null;
}

function isEnvelope(body: unknown): body is ApiErrorEnvelope {
    return typeof body === 'object' && body !== null && 'error' in body && typeof (body as ApiErrorEnvelope).error?.code === 'string';
}

export type QueryValue = string | number | boolean | Array<string | number>;

function withQuery(path: string, query?: Record<string, QueryValue | undefined>): string {
    if (!query) {
        return path;
    }

    const params = new URLSearchParams();
    for (const [key, value] of Object.entries(query)) {
        if (value === undefined) {
            continue;
        }
        if (Array.isArray(value)) {
            value.forEach((v) => params.append(`${key}[]`, String(v)));
        } else {
            params.append(key, String(value));
        }
    }

    const qs = params.toString();

    return qs === '' ? path : `${path}?${qs}`;
}

export interface RequestOptions {
    method?: 'GET' | 'POST' | 'PATCH' | 'DELETE';
    body?: unknown;
    query?: Record<string, QueryValue | undefined>;
    signal?: AbortSignal;
    /** Extra request headers, e.g. `Idempotency-Key` (06 §6). */
    headers?: Record<string, string>;
}

/**
 * `path` is relative to /api/v1, e.g. `/cart`. Resolves to the parsed
 * JSON body, or `null` for 204 No Content.
 */
export async function apiRequest<T>(path: string, options: RequestOptions = {}): Promise<T> {
    const method = options.method ?? 'GET';
    const headers: Record<string, string> = {
        ...options.headers,
        Accept: 'application/json',
        'X-Requested-With': 'XMLHttpRequest',
    };

    const token = xsrfToken();
    if (token !== null) {
        headers['X-XSRF-TOKEN'] = token;
    }

    if (options.body !== undefined) {
        headers['Content-Type'] = 'application/json';
    }

    const response = await fetch(withQuery(`/api/v1${path}`, options.query), {
        method,
        headers,
        credentials: 'same-origin',
        body: options.body === undefined ? undefined : JSON.stringify(options.body),
        signal: options.signal,
    });

    if (response.status === 204) {
        return null as T;
    }

    const body: unknown = await response.json().catch(() => null);

    if (!response.ok) {
        if (isEnvelope(body)) {
            throw new ApiError(response.status, body.error.code, body.error.message, body.error.details, body.error.request_id);
        }

        throw new ApiError(response.status, 'http_error', `Request failed with status ${response.status}.`);
    }

    return body as T;
}
