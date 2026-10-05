#!/usr/bin/env node
/**
 * Ensures catalog content fields resolve to canonical DOM element ids.
 */
import { readFileSync } from 'node:fs'
import { pathToFileURL } from 'node:url'
import { createRequire } from 'node:module'

const require = createRequire(import.meta.url)
const ROOT = new URL('..', import.meta.url).pathname

// Load TS via vite-node alternative: parse catalog keys from grep-friendly static imports won't work.
// Instead validate FIELD_ELEMENT_MAP keys exist in catalog source.
const catalog = readFileSync(`${ROOT}/src/lib/design-system-catalog.ts`, 'utf8')
const registry = readFileSync(`${ROOT}/src/lib/cms-element-registry.ts`, 'utf8')

const mapKeys = [...registry.matchAll(/^\s+([a-z0-9_]+):\s+'/gm)].map((m) => m[1])
const missing = mapKeys.filter((k) => !catalog.includes(`key: '${k}'`) && !catalog.includes(`key: "${k}"`))

if (missing.length) {
  console.error('FIELD_ELEMENT_MAP keys not found in design-system-catalog.ts:', missing.join(', '))
  process.exit(1)
}

console.log(`CMS element registry audit OK (${mapKeys.length} explicit field maps)`)
