/** @typedef {import('../src/lib/settings-admin.ts')} */

function coerceSettingValue(raw) {
  if (raw === null || raw === undefined) return ''
  if (typeof raw === 'string') {
    if (raw === '[object Object]') return ''
    return raw
  }
  if (typeof raw === 'number' || typeof raw === 'boolean') return String(raw)
  if (typeof raw === 'object') {
    if ('value' in raw) return coerceSettingValue(raw.value)
    if ('setting_value' in raw) return coerceSettingValue(raw.setting_value)
    try {
      return JSON.stringify(raw, null, 2)
    } catch {
      return ''
    }
  }
  return String(raw)
}

function flattenAdminSettings(raw) {
  const out = {}
  if (!raw || typeof raw !== 'object') return out
  for (const [key, val] of Object.entries(raw)) {
    out[key] = coerceSettingValue(val)
  }
  return out
}

const nested = {
  hero_heading_1: { value: 'Stay Connected', is_public: true },
  home_why_choose_json: { value: '[{"icon":"🔒","title":"Secure"}]' },
}
const flat = flattenAdminSettings(nested)
if (flat.hero_heading_1 !== 'Stay Connected') {
  console.error('FAIL flatten hero_heading_1', flat.hero_heading_1)
  process.exit(1)
}
if (!flat.home_why_choose_json.includes('Secure')) {
  console.error('FAIL flatten json field')
  process.exit(1)
}
const objField = flattenAdminSettings({ bad: { value: { foo: 'bar' } } })
if (!objField.bad.includes('foo')) {
  console.error('FAIL nested object value', objField.bad)
  process.exit(1)
}
console.log('OK  settings-admin flatten/coerce')
