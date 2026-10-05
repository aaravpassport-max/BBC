/**
 * API client — matches compiled M() function and R{} object in booking-ChxRsZYG.js
 *
 * Token resolution order (from le()):
 *   1. ?s2nri_token= URL param (64 hex chars)
 *   2. window.S2NRI_CONFIG.portalToken
 *   3. localStorage s2nri_portal_token
 *
 * Every request sends:
 *   X-WP-Nonce: from S2NRI_CONFIG.nonce
 *   X-S2NRI-Token: portal token (if present)
 *   _s2nri_token: appended to query string (if present)
 *
 * On 401: clears token, removes from localStorage, throws error
 */

import { apiCoreRequest } from '../../shared/api-core.js'
import { fetchResource, invalidateCache, peekCached } from './resource-cache'

let _cachedToken: string | null = null

function getConfig() {
  return window.S2NRI_CONFIG || {}
}

function resolveToken(): string | null {
  if (_cachedToken) return _cachedToken

  // 1. URL param
  const urlToken = new URLSearchParams(window.location.search).get('s2nri_token')
  if (urlToken && urlToken.length === 64) {
    _cachedToken = urlToken
    try { localStorage.setItem('s2nri_portal_token', urlToken) } catch {}
    return _cachedToken
  }

  // 2. window config
  const configToken = (window.S2NRI_CONFIG || {}).portalToken
  if (configToken && configToken.length === 64) {
    _cachedToken = configToken
    try { localStorage.setItem('s2nri_portal_token', configToken) } catch {}
    return _cachedToken
  }

  // 3. localStorage
  try {
    const stored = localStorage.getItem('s2nri_portal_token')
    if (stored && stored.length === 64) {
      _cachedToken = stored
      return _cachedToken
    }
  } catch {}

  return null
}

// Initialise on module load
resolveToken()

async function request<T = unknown>(
  method: string,
  path: string,
  body: unknown = null,
  isUpload = false
): Promise<T> {
  const token = resolveToken()
  const cfg = getConfig()

  // Shared plumbing (URL building, headers, fetch, JSON parse) — see
  // shared/api-core.js. alwaysJsonContentType: true preserves this
  // project's original behavior (Content-Type set on every non-upload
  // request, not just when a body is present — genuinely differs from
  // src-react's client, kept as an explicit choice, not silently dropped).
  const result = await apiCoreRequest({
    apiBase: cfg.apiBase,
    nonce: cfg.nonce,
    token: token || undefined,
    method,
    path,
    body,
    isUpload,
    alwaysJsonContentType: true,
  })

  if (result.networkError) {
    throw { message: 'Network error. Please check your connection.', status: 0 }
  }

  const response = { status: result.status, ok: result.ok }
  const data = (result.data || {}) as Record<string, unknown>

  // 401: session expired
  if (response.status === 401 && !window.location.pathname.startsWith('/login')) {
    _cachedToken = null
    try { localStorage.removeItem('s2nri_portal_token') } catch {}
    throw {
      message: (data.error as string) || 'Session expired. Please refresh and try again.',
      fields: {},
      status: 401,
    }
  }

  if (!response.ok) {
    throw {
      message: (data.error as string) || 'Something went wrong. Please try again.',
      fields: (data.fields as Record<string, string>) || {},
      status: response.status,
    }
  }

  // Refresh nonce if server sends a new one
  if ((data as Record<string, unknown>).new_nonce && window.S2NRI_CONFIG) {
    window.S2NRI_CONFIG.nonce = (data as Record<string, unknown>).new_nonce as string
  }

  if (method !== 'GET') {
    touchMutationInvalidation(path)
  }

  return data as T
}

function touchMutationInvalidation(path: string) {
  const p = path.split('?')[0]
  if (p.includes('bookings') || p.includes('requests')) invalidateCache('admin/bookings')
  if (p.includes('payments')) invalidateCache('admin/payments')
  if (p.includes('customers')) invalidateCache('admin/customers')
  if (p.includes('services') && p.includes('sections')) invalidateCache('sections')
  if (p.includes('services')) invalidateCache('admin/services')
  if (p.includes('categories')) invalidateCache('admin/categories')
  if (p.includes('tickets')) invalidateCache('admin/tickets')
  if (p.includes('reviews')) invalidateCache('admin/reviews')
  if (p.includes('settings')) invalidateCache('admin/settings')
  if (p.includes('notifications')) invalidateCache('notifications')
}

/**
 * API client — identical interface to R{} in the compiled bundle.
 */
export const api = {
  get:    <T = unknown>(path: string)                           => request<T>('GET',    path),
  /** Stale-while-revalidate GET with session-scoped cache (instant revisits). */
  getCached: <T = unknown>(path: string, opts?: { ttl?: number; persist?: boolean; force?: boolean }) =>
    fetchResource<T>('GET', path, () => request<T>('GET', path), {
      ttl: opts?.ttl,
      persist: opts?.persist ?? true,
      force: opts?.force,
    }),
  peekGet: <T = unknown>(path: string) => peekCached<T>('GET', path),
  post:   <T = unknown>(path: string, body: unknown)            => request<T>('POST',   path, body),
  put:    <T = unknown>(path: string, body: unknown)            => request<T>('PUT',    path, body),
  patch:  <T = unknown>(path: string, body: unknown)            => request<T>('PATCH',  path, body),
  delete: <T = unknown>(path: string)                           => request<T>('DELETE', path),
  upload: <T = unknown>(path: string, form: FormData)           => request<T>('POST',   path, form, true),
}
