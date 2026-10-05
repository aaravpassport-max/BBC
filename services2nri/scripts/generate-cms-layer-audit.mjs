#!/usr/bin/env node
/** Generates e2e/cms-layer-audit.generated.json from cms-element-registry canonical map. */
import { readFileSync, writeFileSync } from 'node:fs'
import { join } from 'node:path'

const ROOT = join(import.meta.dirname, '..')
const registrySrc = readFileSync(`${ROOT}/src/lib/cms-element-registry.ts`, 'utf8')

const PAGE_ROUTES = {
  home: '/',
  blog: '/blog',
  about: '/about',
  pricing: '/pricing',
  contact: '/contact',
  faq: '/faq',
  'how-it-works': '/how-it-works',
  services: '/services',
  service: '/service/complete-property-management',
}

function parseCanonicalMarkers(src) {
  const start = src.indexOf('export const CANONICAL_SECTION_ELEMENTS')
  const end = src.indexOf('/** Explicit setting key', start)
  const block = src.slice(start, end)
  const markers = []
  let page = null
  let section = null
  let inSectionList = false
  for (const line of block.split('\n')) {
    const pageMatch = line.match(/^  ('[^']+'|[\w-]+): \{$/)
    if (pageMatch) {
      page = pageMatch[1].replace(/^'|'$/g, '')
      section = null
      inSectionList = false
      continue
    }
    const sectionMatch = line.match(/^    ([\w_-]+): \[$/)
    if (sectionMatch) {
      section = sectionMatch[1]
      inSectionList = true
      continue
    }
    if (inSectionList && /^    \],/.test(line)) {
      section = null
      inSectionList = false
      continue
    }
    const idMatch = line.match(/id: '([^']+)'/)
    if (idMatch && page && section) {
      markers.push({
        page,
        section,
        element: idMatch[1],
        route: PAGE_ROUTES[page] ?? '/',
      })
    }
  }
  return markers
}

const fieldMapBlock = registrySrc.match(/const FIELD_ELEMENT_MAP[\s\S]*?= (\{[\s\S]*?\n\})/)?.[1] ?? '{}'
const fieldProbes = [...fieldMapBlock.matchAll(/^\s+([a-z0-9_]+):/gm)].map((m) => m[1])

/** Representative save→public probes (admin PUT keys). */
const saveProbes = [
  { key: 'hiw_step2_title', value: 'Layer Audit Step 2', page: 'home', section: 'process', element: 'step_2_title' },
  { key: 'feature_2_title', value: 'Layer Audit Feature 2', page: 'home', section: 'features', element: 'feature_2_title' },
  { key: 'stat_2_label', value: 'Layer Audit Stat', page: 'home', section: 'stats', element: 'stat_2_label' },
  { key: 'faq_1_q', value: 'Layer Audit FAQ One?', page: 'home', section: 'faq', element: 'faq_1_question' },
  { key: 'hiw_page_step1_title', value: 'Layer Page Step', page: 'how-it-works', section: 'process', element: 'page_step_1_title', route: '/how-it-works' },
  { key: 'value_1_title', value: 'Layer Value One', page: 'about', section: 'values', element: 'value_1_title', route: '/about' },
  { key: 'header_whatsapp_label', value: 'Layer WhatsApp', page: 'home', section: 'header', element: 'whatsapp_button' },
]

const out = {
  generatedAt: new Date().toISOString(),
  markerCount: 0,
  markers: parseCanonicalMarkers(registrySrc),
  fieldMapKeyCount: fieldProbes.length,
  saveProbes,
}

out.markerCount = out.markers.length

const outPath = `${ROOT}/e2e/cms-layer-audit.generated.json`
writeFileSync(outPath, `${JSON.stringify(out, null, 2)}\n`)
console.log(`Wrote ${outPath} (${out.markerCount} canonical markers, ${saveProbes.length} save probes)`)
