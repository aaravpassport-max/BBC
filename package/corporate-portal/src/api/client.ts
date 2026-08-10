const API_BASE = import.meta.env.VITE_API_BASE_URL || 'http://localhost:3000';

export class ApiError extends Error {
  code: string;
  details: Record<string, unknown>;
  status: number;

  constructor(status: number, code: string, message: string, details: Record<string, unknown>) {
    super(message);
    this.status = status;
    this.code = code;
    this.details = details;
  }
}

/**
 * A genuine network-level failure — the request never reached the server
 * at all (no connectivity, DNS failure, the server is simply unreachable)
 * — as distinct from ApiError, which means the server WAS reached and
 * responded with an error. Real offline/poor-connectivity hardening
 * starts with this distinction: previously both cases fell through to
 * the same generic "Could not load X" message every screen's catch block
 * already had, giving a user on a dead connection no way to tell "the
 * server is down" from "you have no signal" — and no reason to believe
 * retrying (once they're back online) would help.
 */
export class NetworkError extends Error {
  constructor() {
    super('Could not reach the server. Check your connection and try again.');
  }
}

/**
 * The one place every page's catch block should get its error message
 * from, instead of each writing its own `instanceof ApiError ? ... : ...`
 * — found necessary the hard way: that inline pattern, copied across
 * every screen in every app, checked for ApiError only, so a genuine
 * NetworkError (a real network outage) silently fell through to each
 * page's own generic fallback text ("Could not load X"), discarding the
 * actually-useful "check your connection" message entirely. A single
 * shared helper means this class of bug can only exist in one place, not
 * be reintroduced by the next screen that copies the old pattern.
 */
export function getErrorMessage(err: unknown, fallback: string): string {
  if (err instanceof ApiError || err instanceof NetworkError) return err.message;
  return fallback;
}

function getToken(): string | null {
  return localStorage.getItem('access_token');
}

export function setToken(token: string | null) {
  if (token) localStorage.setItem('access_token', token);
  else localStorage.removeItem('access_token');
}

interface RequestOptions {
  method?: 'GET' | 'POST' | 'PUT' | 'PATCH' | 'DELETE';
  body?: unknown;
  idempotencyKey?: string;
  auth?: boolean;
}

async function request<T>(path: string, options: RequestOptions = {}): Promise<T> {
  const { method = 'GET', body, idempotencyKey, auth = true } = options;

  const headers: Record<string, string> = { 'Content-Type': 'application/json' };
  if (auth) {
    const token = getToken();
    if (token) headers['Authorization'] = `Bearer ${token}`;
  }
  if (idempotencyKey) headers['Idempotency-Key'] = idempotencyKey;

  let res: Response;
  try {
    res = await fetch(`${API_BASE}${path}`, {
      method,
      headers,
      body: body !== undefined ? JSON.stringify(body) : undefined,
    });
  } catch {
    // fetch() itself throws (not a rejected-with-error-status response,
    // an actual thrown exception) specifically when the request never
    // reached the server — offline, DNS failure, connection refused. This
    // is the ONE place in the whole app where that distinction can still
    // be made; by the time an error reaches a page's own catch block,
    // "TypeError: Failed to fetch" and a real 500 look identical unless
    // it's wrapped here.
    throw new NetworkError();
  }

  const text = await res.text();
  let json: Record<string, unknown>;
  try {
    json = text ? JSON.parse(text) : {};
  } catch {
    // A response that isn't valid JSON — a proxy/gateway error page, or a
    // connection that dropped mid-response on a poor network — treated the
    // same as a network failure, since from the caller's perspective the
    // real data never actually arrived either way.
    throw new NetworkError();
  }

  if (!res.ok) {
    const err = (json.error as { code: string; message: string; details?: Record<string, unknown> } | undefined) || {
      code: 'UNKNOWN',
      message: 'Something went wrong.',
      details: {},
    };
    throw new ApiError(res.status, err.code, err.message, err.details || {});
  }
  return json as T;
}

export const api = {
  get: <T>(path: string) => request<T>(path, { method: 'GET' }),
  post: <T>(path: string, body?: unknown, idempotencyKey?: string) =>
    request<T>(path, { method: 'POST', body, idempotencyKey }),
  put: <T>(path: string, body?: unknown) => request<T>(path, { method: 'PUT', body }),
  patch: <T>(path: string, body?: unknown) => request<T>(path, { method: 'PATCH', body }),
  del: <T>(path: string) => request<T>(path, { method: 'DELETE' }),
  public: {
    post: <T>(path: string, body?: unknown) => request<T>(path, { method: 'POST', body, auth: false }),
  },
};

export function newIdempotencyKey(): string {
  return crypto.randomUUID();
}
