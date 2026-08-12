import { getConfig } from '@/config';

export class ApiError extends Error {
  code: string;
  constructor(code: string, message: string) {
    super(message);
    this.code = code;
  }
}

type RequestOptions = RequestInit & { admin?: boolean; ops?: boolean };

export async function api<T>(path: string, opts: RequestOptions = {}): Promise<T> {
  const cfg = getConfig();
  const base = opts.ops ? cfg.opsBase : opts.admin ? cfg.adminBase : cfg.apiBase;
  const url = `${base.replace(/\/$/, '')}${path.startsWith('/') ? path : `/${path}`}`;

  const headers: Record<string, string> = {
    'Content-Type': 'application/json',
    ...(opts.headers as Record<string, string> | undefined),
  };

  if (cfg.nonce) headers['X-WP-Nonce'] = cfg.nonce;

  const token = localStorage.getItem('pms_access_token');
  if (token && !opts.admin && !opts.ops) {
    headers.Authorization = `Bearer ${token}`;
  }

  const res = await fetch(url, { ...opts, headers });
  const data = await res.json().catch(() => ({}));

  if (!res.ok) {
    const err = data?.error ?? data;
    throw new ApiError(err?.code ?? 'REQUEST_FAILED', err?.message ?? res.statusText);
  }

  return data as T;
}

export function setAccessToken(token: string) {
  localStorage.setItem('pms_access_token', token);
}

export function clearAccessToken() {
  localStorage.removeItem('pms_access_token');
}

export function hasAccessToken() {
  return !!localStorage.getItem('pms_access_token');
}
