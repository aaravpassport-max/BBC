#!/usr/bin/env node
/**
 * Frontend ↔ Design System catalog sync audit.
 * Fails when a catalog content/design setting key is never referenced in public frontend source.
 */
import fs from 'node:fs'
import path from 'node:path'
import { fileURLToPath } from 'node:url'

const root = path.join(path.dirname(fileURLToPath(import.meta.url)), '..')
const catalogPath = path.join(root, 'src/lib/design-system-catalog.ts')
const publicDir = path.join(root, 'src/pages/public')
const layoutDir = path.join(root, 'src/components')

const catalogSrc = fs.readFileSync(catalogPath, 'utf8')
const keyMatches = [...catalogSrc.matchAll(/\{\s*key:\s*'([^']+)'/g)].map((m) => m[1])
const uniqueKeys = [...new Set(keyMatches)]

function readTree(dir) {
  const out = []
  for (const ent of fs.readdirSync(dir, { withFileTypes: true })) {
    const p = path.join(dir, ent.name)
    if (ent.isDirectory()) out.push(...readTree(p))
    else if (/\.(tsx|ts|jsx|js)$/.test(ent.name)) out.push(p)
  }
  return out
}

const frontendFiles = [...readTree(publicDir), ...readTree(layoutDir)]
const frontendBlob = frontendFiles.map((f) => fs.readFileSync(f, 'utf8')).join('\n')

const missing = uniqueKeys.filter((key) => {
  if (key.startsWith('css_')) return true
  if (key.startsWith('hide_')) return true
  if (key.endsWith('_json')) return true
  const patterns = [
    `settings.${key}`,
    `settings['${key}']`,
    `settings["${key}"]`,
    `readAdminSetting(settings, '${key}')`,
    `'${key}'`,
  ]
  return !patterns.some((p) => frontendBlob.includes(p))
})

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
  console.warn(`Design CMS sync audit: ${missing.length} keys not referenced in public frontend (see ${outPath})`)
  if (strict) process.exit(1)
} else {
  console.log(`Design CMS sync audit OK (${uniqueKeys.length} catalog keys checked)`)
}
