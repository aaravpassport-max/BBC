/**
 * Public settings visibility for E2E mock API (aligned with SettingsAdminController).
 */
import { readFileSync } from 'node:fs'
import { join, dirname } from 'node:path'
import { fileURLToPath } from 'node:url'

const ROOT = join(dirname(fileURLToPath(import.meta.url)), '../..')

let cachedAllowlist = null

function phpAllowlist() {
  if (cachedAllowlist) return cachedAllowlist
  const adminPhp = readFileSync(`${ROOT}/src/Api/Controllers/Admin/AdminControllers.php`, 'utf8')
  const publicKeyBlock = adminPhp.match(/\$public_keys\s*=\s*\[([\s\S]*?)\];/)?.[1] ?? ''
  cachedAllowlist = new Set([...publicKeyBlock.matchAll(/'([^']+)'/g)].map((m) => m[1]))
  return cachedAllowlist
}

/** @param {string} key */
export function isPublicSettingKey(key) {
  if (!key) return false
  if (phpAllowlist().has(key)) return true
  if (key.startsWith('hide_tmpl_') || key.startsWith('hide_el_')) return true
  if (
    /^value_\d+_(icon|title|desc)$/.test(key) ||
    /^team_\d+_(name|role|bio|img)$/.test(key) ||
    /^about_highlight_\d+_(value|label)$/.test(key) ||
    /^hiw_page_step\d+_(icon|title|desc)$/.test(key) ||
    /^pricing_brand_row_\d+_(feature|us|them)$/.test(key) ||
    /^contact_hours_\d+_(day|hours)$/.test(key)
  ) {
    return true
  }
  if (key.startsWith('header_') || key.startsWith('topbar_') || key.startsWith('footer_col_')) return true
  if (['nav_menu_json', 'footer_quick_links_json', 'footer_locations_json', 'hiw_hero_meta_json'].includes(key)) {
    return true
  }
  if (key.endsWith('_hero_meta_json')) return true
  const catalogPrefixes = [
    'contact_field_',
    'contact_success_',
    'contact_failed_',
    'contact_form_submit_',
    'blog_hero_',
    'blog_search_',
    'blog_categories_',
    'pricing_consultation_',
    'about_cta_primary_',
    'about_cta_secondary_',
    'service_hero_meta_',
    'service_hero_price_',
    'service_mobile_cta_',
    'service_why_',
    'service_wizard_',
    'services_hero_meta_',
    'services_search_',
  ]
  if (catalogPrefixes.some((p) => key.startsWith(p))) return true
  if (key.startsWith('css_') || key.startsWith('hide_section_') || key.startsWith('custom_css_')) return true
  return false
}
