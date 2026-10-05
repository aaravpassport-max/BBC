#!/usr/bin/env node
/**
 * Catalog setting keys referenced on the public SPA must be marked public on save (PHP allowlist / patterns).
 */
import { readFileSync, readdirSync, statSync } from 'node:fs'
import { join } from 'node:path'
const ROOT = join(import.meta.dirname, '..')

const catalogSrc = readFileSync(`${ROOT}/src/lib/design-system-catalog.ts`, 'utf8')
const catalogKeys = [...new Set([...catalogSrc.matchAll(/\{\s*key:\s*'([^']+)'/g)].map((m) => m[1]))]

import { isPublicSettingKey } from '../e2e/lib/public-settings-policy.mjs'

const isPublicOnSave = isPublicSettingKey

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
