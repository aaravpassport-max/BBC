/**
 * Admin settings API returns `{ key: { value, is_public } }` from PHP.
 * Form controls must always bind plain strings — never raw objects.
 */

export function coerceSettingValue(raw: unknown): string {
  if (raw === null || raw === undefined) return ''
  if (typeof raw === 'string') {
    if (raw === '[object Object]') return ''
    return raw
  }
  if (typeof raw === 'number' || typeof raw === 'boolean') return String(raw)
  if (typeof raw === 'object') {
    const o = raw as Record<string, unknown>
    if ('value' in o) return coerceSettingValue(o.value)
    if ('setting_value' in o) return coerceSettingValue(o.setting_value)
    try {
      return JSON.stringify(raw, null, 2)
    } catch {
      return ''
    }
  }
  return String(raw)
}

/** Flatten admin GET `settings` into string values for controlled inputs. */
export function flattenAdminSettings(raw: Record<string, unknown> | undefined | null): Record<string, string> {
  const out: Record<string, string> = {}
  if (!raw || typeof raw !== 'object') return out
  for (const [key, val] of Object.entries(raw)) {
    out[key] = coerceSettingValue(val)
  }
  return out
}

export function readAdminSetting(settings: Record<string, string>, key: string): string {
  return coerceSettingValue(settings[key])
}

/** Build PUT payload — never send object references. */
export function prepareSettingsPayload(
  settings: Record<string, string>,
  keys: string[],
): Record<string, string> {
  const payload: Record<string, string> = {}
  for (const key of keys) {
    payload[key] = readAdminSetting(settings, key)
  }
  return payload
}

export type AdminSettingsResponse = {
  settings?: Record<string, unknown>
  settings_flat?: Record<string, string>
}

export function normalizeSettingsApiResponse(data: AdminSettingsResponse): Record<string, string> {
  if (data.settings_flat && typeof data.settings_flat === 'object') {
    return flattenAdminSettings(data.settings_flat as Record<string, unknown>)
  }
  return flattenAdminSettings(data.settings)
}
