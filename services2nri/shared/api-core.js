/**
 * S2NRI Shared API Core — the low-level fetch plumbing common to BOTH
 * React projects in this plugin:
 *   - src/lib/api.ts                (main app: public site + /admin + /dashboard)
 *   - src-react/src/utils/api.js    (Builder: /s2nri-builder)
 *
 * Deliberately narrow scope. Before this file existed, the two projects
 * had two independent implementations of "build URL, set headers, fetch,
 * parse JSON" that happened to do the same thing — genuine duplication,
 * safe to share. But they ALSO differ in ways that are NOT just style:
 *
 *   - src/lib/api.ts: 3-tier token resolution (URL param → config →
 *     localStorage) with caching and persistence; structured error object
 *     on failure ({ message, fields, status }); special 401 handling that
 *     clears the cached token and skips itself on /login.
 *   - src-react/src/utils/api.js: token read directly from config, no
 *     caching/persistence, no localStorage fallback; throws a plain Error;
 *     no special 401 handling.
 *
 * Those differences reflect real, different needs (the Builder is
 * WP-admin/staff-only, the main app is customer-facing and needs to
 * survive page reloads and shared/bookmarked links with a token in the
 * URL). Forcing them to be identical would be a behavior change dressed up
 * as a refactor. So THIS file only contains the part with no meaningful
 * behavioral choice attached — everything else stays in each project's own
 * wrapper, calling into this.
 *
 * Returns a plain result object rather than throwing, so each caller can
 * apply its own error-shape/401 policy on top without this file needing to
 * know what that policy is.
 */

/**
 * @param {Object} params
 * @param {string} params.apiBase   - Base URL, no trailing slash (e.g. from window.S2NRI_CONFIG.apiBase)
 * @param {string} [params.nonce]   - X-WP-Nonce value
 * @param {string} [params.token]   - Portal token, if resolved by the caller
 * @param {string} params.method    - HTTP method
 * @param {string} params.path      - Path relative to apiBase (leading slash optional)
 * @param {*} [params.body]         - Request body (object for JSON, FormData for uploads)
 * @param {boolean} [params.isUpload] - true = send as FormData, skip Content-Type/JSON.stringify
 * @param {boolean} [params.alwaysJsonContentType] - true = set Content-Type on every non-upload
 *   request regardless of body presence (src/lib/api.ts's original behavior); false = only when
 *   body is present (src-react/src/utils/api.js's original behavior). Found to genuinely differ
 *   between the two callers — preserved as an explicit choice, not silently unified.
 * @returns {Promise<{ok: boolean, status: number, data: any, networkError?: boolean}>}
 */
export async function apiCoreRequest({ apiBase, nonce, token, method, path, body, isUpload, alwaysJsonContentType = false }) {
  let url = `${apiBase}/${String(path).replace(/^\//, '')}`
  if (token) {
    const sep = url.includes('?') ? '&' : '?'
    url += `${sep}_s2nri_token=${encodeURIComponent(token)}`
  }

  const headers = { 'X-WP-Nonce': nonce || '' }
  const shouldSetJsonContentType = !isUpload && (alwaysJsonContentType || body)
  if (shouldSetJsonContentType) headers['Content-Type'] = 'application/json'
  if (token) headers['X-S2NRI-Token'] = token

  const init = { method, headers, credentials: 'include' }
  if (body) init.body = isUpload ? body : JSON.stringify(body)

  let response
  try {
    response = await fetch(url, init)
  } catch {
    return { ok: false, status: 0, data: null, networkError: true }
  }

  const data = await response.json().catch(() => ({}))
  return { ok: response.ok, status: response.status, data }
}
