import type { ApiError, IAConfig } from '@/types';

declare global {
  interface Window {
    IA_CONFIG: IAConfig;
  }
}

const CONFIG = window.IA_CONFIG;

/**
 * In-memory access token — deliberately NOT localStorage/sessionStorage.
 * The refresh token lives server-side in an HttpOnly cookie (class-jwt.php);
 * keeping the short-lived access token in memory only (lost on hard reload,
 * recovered via /auth/refresh) avoids ever putting a bearer token somewhere
 * an XSS payload or a browser extension could read it from disk.
 */
let accessToken: string | null = null;
let refreshInFlight: Promise<boolean> | null = null;

export function setAccessToken(tok: string | null) {
  accessToken = tok;
}
export function getAccessToken() {
  return accessToken;
}

function toApiError(status: number, body: unknown): ApiError {
  const b = (body ?? {}) as Record<string, unknown>;
  const data = (b.data ?? {}) as Record<string, unknown>;
  return {
    code: (b.code as string) || 'ia_unknown',
    message: (b.message as string) || 'Something went wrong. Please try again.',
    status: (data.status as number) ?? status,
    retryable: Boolean(data.retryable) || status === 429 || status >= 500,
    extra: data,
  };
}

interface RequestOpts extends RequestInit {
  /** Skip the automatic silent-refresh-and-retry-once on a 401. Used by /auth/refresh itself to avoid recursion. */
  skipRefresh?: boolean;
}

/**
 * CLIENT-SIDE HALF OF THE 15-MINUTE-TOKEN FIX: the backend audit flagged
 * that a live interview could silently 401 mid-session if the access token
 * (900s TTL) expired and nothing refreshed it. This wrapper transparently
 * attempts one silent /auth/refresh on any 401 and retries the original
 * request exactly once — so a long interview never surfaces a raw auth
 * error to the user mid-conversation, it just keeps working.
 */
export async function apiFetch<T>(path: string, opts: RequestOpts = {}): Promise<T> {
  const doFetch = async (): Promise<Response> => {
    const headers = new Headers(opts.headers);
    if (accessToken) headers.set('Authorization', `Bearer ${accessToken}`);
    if (opts.body && !(opts.body instanceof FormData) && !headers.has('Content-Type')) {
      headers.set('Content-Type', 'application/json');
    }
    return fetch(`${CONFIG.apiBase}${path}`, {
      ...opts,
      headers,
      credentials: 'include', // send the HttpOnly refresh-token cookie
    });
  };

  let res = await doFetch();

  if (res.status === 401 && !opts.skipRefresh && path !== '/auth/refresh') {
    const refreshed = await silentRefresh();
    if (refreshed) res = await doFetch();
  }

  if (!res.ok) {
    let body: unknown = null;
    try { body = await res.json(); } catch { /* non-JSON error body */ }
    throw toApiError(res.status, body);
  }
  if (res.status === 204) return undefined as T;
  return (await res.json()) as T;
}

/** De-duplicated: concurrent 401s from multiple in-flight requests trigger only one refresh call, not one per request. */
function silentRefresh(): Promise<boolean> {
  if (!refreshInFlight) {
    refreshInFlight = apiFetch<{ access_token: string }>('/auth/refresh', {
      method: 'POST',
      skipRefresh: true,
    })
      .then((r) => {
        setAccessToken(r.access_token);
        return true;
      })
      .catch(() => {
        setAccessToken(null);
        return false;
      })
      .finally(() => {
        refreshInFlight = null;
      });
  }
  return refreshInFlight;
}

/**
 * Retry helper for the specific "retryable" errors the backend now flags
 * (A1's Claude retry/backoff, A2's turn idempotency, B/network failures).
 * Exponential backoff with jitter, capped attempts — mirrors the server's
 * own retry policy in class-claude.php so the two layers don't fight.
 */
export async function withRetry<T>(fn: () => Promise<T>, maxAttempts = 3): Promise<T> {
  let lastErr: unknown;
  for (let attempt = 0; attempt < maxAttempts; attempt++) {
    try {
      return await fn();
    } catch (err) {
      lastErr = err;
      const apiErr = err as ApiError;
      if (!apiErr.retryable || attempt === maxAttempts - 1) throw err;
      const backoff = 400 * 2 ** attempt + Math.random() * 250;
      await new Promise((r) => setTimeout(r, backoff));
    }
  }
  throw lastErr;
}

export const config = CONFIG;
