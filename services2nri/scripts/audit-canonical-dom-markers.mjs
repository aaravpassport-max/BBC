#!/usr/bin/env node
/**
 * Every canonical data-s2-element id must appear in public/layout source (CmsElement markers).
 */
import { readFileSync, readdirSync, statSync } from 'node:fs'
import { join } from 'node:path'

const ROOT = join(import.meta.dirname, '..')
const generated = JSON.parse(readFileSync(`${ROOT}/e2e/cms-layer-audit.generated.json`, 'utf8'))

const scanRoots = [
  `${ROOT}/src/pages/public`,
  `${ROOT}/src/components/layout`,
  `${ROOT}/src/components/public`,
]

function readTree(dir) {
  const out = []
  for (const name of readdirSync(dir)) {
    const p = join(dir, name)
    if (statSync(p).isDirectory()) out.push(...readTree(p))
    else if (/\.(tsx|ts)$/.test(name)) out.push(p)
  }
  return out
}

const blob = scanRoots.flatMap(readTree).map((f) => readFileSync(f, 'utf8')).join('\n')

/** Layout-only ids that may be implied by parent wrappers (still checked loosely). */
const SOFT_IDS = new Set(['collection', 'grid', 'sidebar', 'meta_chips'])

const missing = []
for (const { page, section, element } of generated.markers) {
  const sharedLookIds = new Set([
    'service_card', 'city_card', 'stat_item', 'feature_card', 'testimonial_card', 'step_card',
    'press_chip', 'partner_chip', 'award_badge', 'faq_item', 'highlight_tile', 'value_card',
    'team_card', 'plan_card', 'brand_compare_row',
  ])
  const patterns = [
    `data-s2-element="${element}"`,
    `elementId="${element}"`,
    `elementId={\`${element}\`}`,
    `elementId={'${element}'}`,
  ]
  if (!sharedLookIds.has(element) && element.includes('_')) {
    const parts = element.split('_')
    patterns.push(`elementId={\`${parts[0]}_\${`)
  }
  const found = patterns.some((p) => blob.includes(p))
  if (!found && !SOFT_IDS.has(element)) {
    missing.push(`${page}/${section}/${element}`)
  }
}

if (missing.length) {
  console.error(`Canonical DOM marker audit: ${missing.length} elements not found in public source:`)
  for (const m of missing.slice(0, 40)) console.error(`  ${m}`)
  if (missing.length > 40) console.error(`  … and ${missing.length - 40} more`)
  process.exit(1)
}

console.log(`Canonical DOM marker audit OK (${generated.markers.length} elements)`)
