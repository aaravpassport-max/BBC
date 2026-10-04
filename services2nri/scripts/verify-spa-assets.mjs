#!/usr/bin/env node
/**
 * Ensures app.js static imports match chunk export names and BUILD_STAMP matches disk.
 * Catches "does not provide an export named" before deploy.
 */
import { createHash } from 'node:crypto'
import { readFileSync, readdirSync, existsSync } from 'node:fs'
import { join, dirname } from 'node:path'
import { fileURLToPath } from 'node:url'

const root = join(dirname(fileURLToPath(import.meta.url)), '..')
const assets = join(root, 'assets')
const chunksDir = join(assets, 'chunks')

function collectAssetPaths() {
  const paths = ['app.js', 'app.css', 'boot-config.js', 'boot-watchdog.js', 'boot-sw-cleanup.js']
  if (existsSync(chunksDir)) {
    for (const name of readdirSync(chunksDir).filter((f) => f.endsWith('.js')).sort()) {
      paths.push(`chunks/${name}`)
    }
  }
  return paths
}

function fingerprint(paths) {
  const parts = paths.map((rel) =>
    createHash('md5').update(readFileSync(join(assets, rel))).digest('hex'),
  )
  return createHash('md5').update(parts.join(':')).digest('hex').slice(0, 12)
}

function parseChunkExports(source) {
  const m = source.match(/export\s*\{([^}]+)\}\s*;?\s*$/)
  if (!m) {
    const m2 = source.match(/export\{([^}]+)\}/)
    if (!m2) throw new Error('No export block found in chunk')
    return parseExportList(m2[1])
  }
  return parseExportList(m[1])
}

function parseExportList(inner) {
  const names = new Set()
  for (const part of inner.split(',')) {
    const bit = part.trim()
    if (!bit) continue
    const asMatch = bit.match(/\s+as\s+([A-Za-z_$][\w$]*)\s*$/)
    if (asMatch) names.add(asMatch[1])
    else {
      const sym = bit.match(/([A-Za-z_$][\w$]*)$/)
      if (sym) names.add(sym[1])
    }
  }
  return names
}

function parseStaticImportsFromApp(appSource) {
  const imports = new Map()
  const re = /import\s*\{([^}]+)\}\s*from\s*["']\.\/chunks\/([^"']+)["']/g
  let m
  while ((m = re.exec(appSource)) !== null) {
    const chunk = m[2].replace(/\.js$/, '') + '.js'
    const needed = new Set()
    for (const part of m[1].split(',')) {
      const bit = part.trim()
      const im = bit.match(/^([A-Za-z_$][\w$]*)\s+as\s+/)
      if (im) needed.add(im[1])
      else {
        const sym = bit.match(/^([A-Za-z_$][\w$]*)/)
        if (sym) needed.add(sym[1])
      }
    }
    imports.set(chunk, needed)
  }
  return imports
}

let fail = 0
const err = (msg) => {
  console.error('FAIL', msg)
  fail++
}

const paths = collectAssetPaths()
const stampPath = join(assets, 'BUILD_STAMP.txt')
if (!existsSync(stampPath)) err('assets/BUILD_STAMP.txt missing — run npm run build')
const recorded = existsSync(stampPath) ? readFileSync(stampPath, 'utf8').trim() : ''
const computed = fingerprint(paths)
if (recorded !== computed) {
  err(`BUILD_STAMP mismatch: file=${recorded} computed=${computed}`)
} else {
  console.log('OK  BUILD_STAMP', recorded, `(${paths.length} files)`)
}

const appPath = join(assets, 'app.js')
if (!existsSync(appPath)) err('assets/app.js missing')
const appSource = readFileSync(appPath, 'utf8')
const imports = parseStaticImportsFromApp(appSource)

for (const [chunkFile, needed] of imports) {
  const chunkPath = join(assets, 'chunks', chunkFile)
  if (!existsSync(chunkPath)) {
    err(`app.js imports ./chunks/${chunkFile} but file missing`)
    continue
  }
  let exports
  try {
    exports = parseChunkExports(readFileSync(chunkPath, 'utf8'))
  } catch (e) {
    err(`chunks/${chunkFile}: ${e.message}`)
    continue
  }
  for (const name of needed) {
    if (!exports.has(name)) {
      err(
        `chunks/${chunkFile} missing export "${name}" (app.js would throw at runtime)`,
      )
    }
  }
  if (needed.size) {
    console.log(`OK  app.js ↔ chunks/${chunkFile} (${needed.size} static imports)`)
  }
}

const requiredChunks = ['booking.js', 'router.js', 'react.js']
for (const c of requiredChunks) {
  if (!existsSync(join(chunksDir, c))) err(`required chunk missing: ${c}`)
  else console.log('OK  required chunk', c)
}

process.exit(fail > 0 ? 1 : 0)
