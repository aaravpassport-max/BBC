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

export class NetworkError extends Error {
  constructor() {
    super('Could not reach the server. Check your connection and try again.');
  }
}

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

export function getRefreshToken(): string | null {
  return localStorage.getItem('refresh_token');
}

export function setRefreshToken(token: string | null) {
  if (token) localStorage.setItem('refresh_token', token);
  else localStorage.removeItem('refresh_token');
}

let refreshInFlight: Promise<boolean> | null = null;

async function tryRefreshAccessToken(): Promise<boolean> {
  if (refreshInFlight) return refreshInFlight;

  refreshInFlight = (async () => {
    const refreshToken = getRefreshToken();
    const deviceId = localStorage.getItem('device_id');
    if (!refreshToken || refreshToken === 'demo-refresh-token' || !deviceId) return false;

    try {
      const res = await fetch(`${API_BASE}/v1/auth/refresh`, {
        method: 'POST',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify({ refresh_token: refreshToken, device_id: deviceId }),
      });
      if (!res.ok) return false;
      const json = (await res.json()) as { access_token: string; refresh_token: string };
      setToken(json.access_token);
      setRefreshToken(json.refresh_token);
      return true;
    } catch {
      return false;
    } finally {
      refreshInFlight = null;
    }
  })();

  return refreshInFlight;
}

export async function refreshSession(): Promise<boolean> {
  return tryRefreshAccessToken();
}

export async function logoutSession(): Promise<void> {
  const refreshToken = getRefreshToken();
  const deviceId = localStorage.getItem('device_id');
  const accessToken = getToken();

  if (refreshToken && refreshToken !== 'demo-refresh-token' && deviceId && accessToken) {
    try {
      await fetch(`${API_BASE}/v1/auth/logout`, {
        method: 'POST',
        headers: {
          'Content-Type': 'application/json',
          Authorization: `Bearer ${accessToken}`,
        },
        body: JSON.stringify({ refresh_token: refreshToken, device_id: deviceId }),
      });
    } catch {
      // Best-effort
    }
  }

  setToken(null);
  setRefreshToken(null);
}

interface RequestOptions {
  method?: 'GET' | 'POST' | 'PUT' | 'DELETE';
  body?: unknown;
  idempotencyKey?: string;
  auth?: boolean;
  retried?: boolean;
}

async function request<T>(path: string, options: RequestOptions = {}): Promise<T> {
  const { method = 'GET', body, idempotencyKey, auth = true, retried = false } = options;

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
    throw new NetworkError();
  }

  if (res.status === 401 && auth && !retried) {
    const refreshed = await tryRefreshAccessToken();
    if (refreshed) {
      return request<T>(path, { ...options, retried: true });
    }
  }

  const text = await res.text();
  let json: Record<string, unknown>;
  try {
    json = text ? JSON.parse(text) : {};
  } catch {
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
  del: <T>(path: string) => request<T>(path, { method: 'DELETE' }),
  public: {
    post: <T>(path: string, body?: unknown) => request<T>(path, { method: 'POST', body, auth: false }),
  },
};

export function newIdempotencyKey(): string {
  return crypto.randomUUID();
}
