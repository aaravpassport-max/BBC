#!/usr/bin/env node
/**
 * Catalog setting keys referenced on the public SPA must be marked public on save (PHP allowlist / patterns).
 */
import { readFileSync, readdirSync, statSync } from 'node:fs'
import { join } from 'node:path'
const ROOT = join(import.meta.dirname, '..')

const catalogSrc = readFileSync(`${ROOT}/src/lib/design-system-catalog.ts`, 'utf8')
const catalogKeys = [...new Set([...catalogSrc.matchAll(/\{\s*key:\s*'([^']+)'/g)].map((m) => m[1]))]

const adminPhp = readFileSync(`${ROOT}/src/Api/Controllers/Admin/AdminControllers.php`, 'utf8')
const publicKeyBlock = adminPhp.match(/\$public_keys\s*=\s*\[([\s\S]*?)\];/)?.[1] ?? ''
const publicKeys = new Set([...publicKeyBlock.matchAll(/'([^']+)'/g)].map((m) => m[1]))

function isPublicOnSave(key) {
  if (publicKeys.has(key)) return true
  if (key.startsWith('hide_tmpl_') || key.startsWith('hide_el_')) return true
  if (/^value_\d+_(icon|title|desc)$/.test(key)) return true
  if (/^team_\d+_(name|role|bio|img)$/.test(key)) return true
  if (/^about_highlight_\d+_(value|label)$/.test(key)) return true
  if (/^hiw_page_step\d+_(icon|title|desc)$/.test(key)) return true
  if (/^pricing_brand_row_\d+_(feature|us|them)$/.test(key)) return true
  if (/^contact_hours_\d+_(day|hours)$/.test(key)) return true
  if (key.startsWith('header_') || key.startsWith('topbar_')) return true
  if (key.startsWith('footer_col_')) return true
  if (['nav_menu_json', 'footer_quick_links_json', 'footer_locations_json', 'hiw_hero_meta_json'].includes(key)) return true
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
  if (key.startsWith('css_') || key.startsWith('hide_section_')) return true
  if (key.startsWith('custom_css_')) return true
  return false
}

function readTree(dir) {
  const out = []
  for (const name of readdirSync(dir)) {
    const p = join(dir, name)
    if (statSync(p).isDirectory()) out.push(...readTree(p))
    else if (/\.(tsx|ts)$/.test(name)) out.push(p)
  }
  return out
}

const publicRoots = [`${ROOT}/src/pages/public`, `${ROOT}/src/components/layout`]
const blob = publicRoots.flatMap(readTree).map((f) => readFileSync(f, 'utf8')).join('\n')

const referenced = catalogKeys.filter((key) => {
  if (key.startsWith('hide_')) return false
  return (
    blob.includes(`settings.${key}`) ||
    blob.includes(`['${key}']`) ||
    blob.includes(`"${key}"`) ||
    blob.includes(`\`${key}\``)
  )
})

const notPublic = referenced.filter((k) => !isPublicOnSave(k))

if (notPublic.length) {
  console.error('Public SPA references catalog keys that are not public on admin save:')
  for (const k of notPublic.sort()) console.error(`  ${k}`)
  process.exit(1)
}

console.log(`Public CMS key audit OK (${referenced.length} catalog keys referenced on public UI)`)
