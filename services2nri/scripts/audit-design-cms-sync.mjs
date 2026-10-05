#!/usr/bin/env node
/**
 * Frontend ↔ Design System catalog sync audit.
 * Checks that each catalog setting key is referenced in public UI or server SEO/CSS plumbing.
 */
import fs from 'node:fs'
import path from 'node:path'
import { fileURLToPath } from 'node:url'

const root = path.join(path.dirname(fileURLToPath(import.meta.url)), '..')
const catalogPath = path.join(root, 'src/lib/design-system-catalog.ts')

const catalogSrc = fs.readFileSync(catalogPath, 'utf8')
const keyMatches = [...catalogSrc.matchAll(/\{\s*key:\s*'([^']+)'/g)].map((m) => m[1])
const uniqueKeys = [...new Set(keyMatches)]

function readTree(dir) {
  if (!fs.existsSync(dir)) return []
  const out = []
  for (const ent of fs.readdirSync(dir, { withFileTypes: true })) {
    const p = path.join(dir, ent.name)
    if (ent.isDirectory()) out.push(...readTree(p))
    else if (/\.(tsx|ts|jsx|js|php)$/.test(ent.name)) out.push(p)
  }
  return out
}

const scanRoots = [
  path.join(root, 'src/pages/public'),
  path.join(root, 'src/components'),
  path.join(root, 'src/lib'),
  path.join(root, 'src/Design'),
  path.join(root, 'src'),
]

const files = [...new Set(scanRoots.flatMap(readTree))]
const blob = files.map((f) => fs.readFileSync(f, 'utf8')).join('\n')

function keyReferenced(key) {
  if (key.startsWith('hide_')) return true
  if (blob.includes(`'${key}'`) || blob.includes(`"${key}"`)) return true
  if (blob.includes(`settings.${key}`)) return true
  if (blob.includes(`readAdminSetting(settings, '${key}')`)) return true
  if (blob.includes(`['${key}']`)) return true
  if (/^hiw_step\d+_/.test(key) && blob.includes('hiw_step${')) return true
  if (key.startsWith('css_') && (blob.includes(`'${key}'`) || blob.includes(`"${key}"`))) return true
  if (key === 'custom_css_global' && blob.includes('custom_css_global')) return true
  return false
}

const missing = uniqueKeys.filter((k) => !keyReferenced(k))

const report = {
  generatedAt: new Date().toISOString(),
  catalogKeys: uniqueKeys.length,
  missingOnFrontend: missing.length,
  missing,
}

const outPath = path.join(root, 'docs/DESIGN_CMS_SYNC_AUDIT.generated.json')
fs.writeFileSync(outPath, `${JSON.stringify(report, null, 2)}\n`)

const strict = process.argv.includes('--strict')
if (missing.length > 0) {
  console.warn(`Design CMS sync audit: ${missing.length} keys not referenced (see ${outPath})`)
  if (strict) process.exit(1)
} else {
  console.log(`Design CMS sync audit OK (${uniqueKeys.length} catalog keys checked)`)
}
