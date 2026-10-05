#!/usr/bin/env node
/**
 * Static guard: rich panel save keys must mirror RICH_FIELD_EXCLUDE (no drift like pre-4.7.90 HIW bug).
 */
import { readFileSync } from 'node:fs'
import { join } from 'node:path'

const ROOT = join(import.meta.dirname, '..')
const richSrc = readFileSync(join(ROOT, 'src/pages/admin/section-content-rich-panel.tsx'), 'utf8')
const catalogSrc = readFileSync(join(ROOT, 'src/lib/design-system-catalog.ts'), 'utf8')

let failed = false
const fail = (msg) => {
  console.error(`FAIL ${msg}`)
  failed = true
}

if (!richSrc.includes('return [...(RICH_FIELD_EXCLUDE[contentPanel] ?? [])]')) {
  fail('richPanelSaveKeys must return RICH_FIELD_EXCLUDE[contentPanel] (single source of truth)')
}

const excludeBlock = richSrc.slice(
  richSrc.indexOf('const RICH_FIELD_EXCLUDE'),
  richSrc.indexOf('export function resolveContentPanel'),
)

const requiredSnippets = [
  ['hiw_steps', 'hiw_step1_title'],
  ['hiw_steps', 'hiw_page_steps_json'],
  ['why_choose', 'home_why_choose_json'],
  ['why_choose', 'feature_1_title'],
  ['services_directory', 'services_show_search'],
  ['chrome_header', 'header_whatsapp_label'],
  ['chrome_footer', 'footer_quick_links_json'],
]

for (const [panel, key] of requiredSnippets) {
  const panelRe = new RegExp(`\\n\\s{2}${panel}:\\s*\\[([\\s\\S]*?)\\],`)
  const m = excludeBlock.match(panelRe)
  if (!m) {
    fail(`Missing RICH_FIELD_EXCLUDE entry for ${panel}`)
    continue
  }
  const body = m[1]
  const ok =
    body.includes(`'${key}'`) ||
    (key.startsWith('hiw_step') && body.includes('HIW_STEP_KEYS')) ||
    (key.startsWith('feature_') && body.includes("startsWith('feature_')"))
  if (!ok) fail(`${panel} exclude list must include ${key} (or spread macro)`)
}

const panelsNeedingExclude = new Set(
  [...catalogSrc.matchAll(/contentPanel:\s*'([^']+)'/g)].map((m) => m[1]),
)
const registryOnly = new Set([
  'services_registry',
  'cities_registry',
  'testimonials_registry',
  'faq_database',
  'blog_posts',
  'category_catalog',
  'service_page_band',
  'locations_cities',
  'legal_document',
  'registry_hub',
])

for (const panel of panelsNeedingExclude) {
  if (registryOnly.has(panel)) continue
  if (!excludeBlock.includes(`${panel}:`)) {
    fail(`Catalog contentPanel '${panel}' has no RICH_FIELD_EXCLUDE block`)
  }
}

if (failed) process.exit(1)
console.log('Rich panel save audit OK (unified save keys + critical panels covered)')
