/**
 * S2NRI Builder — API Client
 * Single point of communication with the PHP REST backend.
 * Reads config from window.S2NRI_BUILDER injected by BuilderPage.php.
 */

import { apiCoreRequest } from '../../../shared/api-core.js'

function getConfig() {
  return window.S2NRI_BUILDER || {}
}

function apiBase() {
  return (getConfig().apiBase || '').replace(/\/$/, '')
}

function nonce() {
  return getConfig().nonce || ''
}

function portalToken() {
  return getConfig().portalToken || ''
}

async function request(method, path, body = null, isFormData = false) {
  // Shared plumbing (URL building, headers, fetch, JSON parse) — see
  // shared/api-core.js. alwaysJsonContentType left at its default (false)
  // to preserve this project's original behavior exactly: Content-Type is
  // only set when a body is actually present, unlike src/lib/api.ts which
  // sets it unconditionally for non-uploads.
  const result = await apiCoreRequest({
    apiBase: apiBase(),
    nonce: nonce(),
    token: portalToken() || undefined,
    method,
    path,
    body,
    isUpload: isFormData,
  })

  if (result.networkError) {
    throw new Error('Network error. Please check your connection.')
  }

  if (!result.ok) {
    const err = result.data || {}
    throw new Error(err.message || err.error || `HTTP ${result.status}`)
  }

  return result.data
}

export const api = {
  get: (path, params = {}) => {
    const qs = Object.keys(params).length
      ? '?' + new URLSearchParams(params).toString()
      : ''
    return request('GET', path + qs)
  },
  post: (path, body) => request('POST', path, body),
  put: (path, body) => request('PUT', path, body),
  patch: (path, body) => request('PATCH', path, body),
  delete: (path) => request('DELETE', path),
  upload: (path, formData) => request('POST', path, formData, true),
}

export default api
