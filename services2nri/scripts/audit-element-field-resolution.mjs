#!/usr/bin/env node
/**
 * Flags catalog content fields that resolve to anonymous field_N ids (Elements tab gaps).
 */
import { readFileSync } from 'node:fs'
import { pathToFileURL } from 'node:url'

const ROOT = new URL('..', import.meta.url).pathname

const { resolveCatalogElementId, canonicalElementsForSection } = await import(
  pathToFileURL(`${ROOT}/src/lib/cms-element-registry.ts`).href
)

const catalogSrc = readFileSync(`${ROOT}/src/lib/design-system-catalog.ts`, 'utf8')
const pageBlocks = [...catalogSrc.matchAll(/id:\s*'([^']+)'[\s\S]*?sections:\s*\[([\s\S]*?)\n\s*\]/g)]

const orphans = []

function extractSections(block) {
  const sections = []
  const re = /sectionKey:\s*'([^']+)'[\s\S]*?contentFields:\s*\[([\s\S]*?)\n\s*\]/g
  let m
  while ((m = re.exec(block))) {
    const sectionKey = m[1]
    const fieldsBlock = m[2]
    const keys = [...fieldsBlock.matchAll(/key:\s*'([^']+)'/g)].map((x) => x[1])
    sections.push({ sectionKey, keys })
  }
  return sections
}

for (const [, pageId, sectionsBlob] of pageBlocks) {
  if (pageId === 'foundation' || pageId === '_page') continue
  for (const { sectionKey, keys } of extractSections(sectionsBlob)) {
    const canonical = new Set(canonicalElementsForSection(pageId, sectionKey).map((e) => e.id))
    keys.forEach((key, index) => {
      const elementId = resolveCatalogElementId(pageId, sectionKey, { key }, index)
      if (elementId.startsWith('field_') || (!canonical.has(elementId) && elementId.includes('_'))) {
        // allow mapped sub-elements like step_1_title
        const knownItem =
          /^step_\d+_(title|desc|icon)$/.test(elementId) ||
          /^page_step_\d+_(title|desc|icon)$/.test(elementId) ||
          /^value_\d+_(icon|title|desc)$/.test(elementId) ||
          /^team_member_\d+_(name|role|bio|photo)$/.test(elementId) ||
          /^highlight_\d+_(value|label)$/.test(elementId) ||
          /^brand_row_\d+_(feature|us|them)$/.test(elementId) ||
          /^hours_\d+_(day|hours)$/.test(elementId) ||
          /^plan_\d+_(name|subtitle|price)$/.test(elementId) ||
          /^service_\d+_(media|title|desc|cta)$/.test(elementId) ||
          /^city_\d+_(photo|eyebrow|name)$/.test(elementId)
        if (!knownItem && elementId.startsWith('field_')) {
          orphans.push({ pageId, sectionKey, key, elementId })
        }
      }
    })
  }
}

if (orphans.length) {
  console.error('Catalog fields with weak Elements tab mapping:')
  for (const o of orphans) {
    console.error(`  ${o.pageId}/${o.sectionKey}: ${o.key} → ${o.elementId}`)
  }
  process.exit(1)
}

console.log('Element field resolution audit OK (no orphan field_N mappings in scanned sections)')
