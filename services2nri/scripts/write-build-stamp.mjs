#!/usr/bin/env node
/**
 * Writes assets/BUILD_STAMP.txt after Vite build.
 * Single stamp versions app.js, app.css, and every chunk together so partial
 * CDN/browser caches cannot mix exports from different builds.
 */
import { createHash } from 'node:crypto'
import { readdirSync, readFileSync, writeFileSync, existsSync } from 'node:fs'
import { join, dirname } from 'node:path'
import { fileURLToPath } from 'node:url'

const root = join(dirname(fileURLToPath(import.meta.url)), '..')
const assets = join(root, 'assets')
const chunksDir = join(assets, 'chunks')

/** @returns {string[]} relative paths under assets/ */
function collectAssetPaths() {
  const paths = ['app.js', 'app.css']
  if (existsSync(chunksDir)) {
    for (const name of readdirSync(chunksDir).filter((f) => f.endsWith('.js')).sort()) {
      paths.push(`chunks/${name}`)
    }
  }
  return paths
}

function fingerprint(paths) {
  const parts = []
  for (const rel of paths) {
    const abs = join(assets, rel)
    if (!existsSync(abs)) {
      throw new Error(`Missing asset for BUILD_STAMP: ${rel}`)
    }
    parts.push(createHash('md5').update(readFileSync(abs)).digest('hex'))
  }
  return createHash('md5').update(parts.join(':')).digest('hex').slice(0, 12)
}

const paths = collectAssetPaths()
const stamp = fingerprint(paths)
const out = join(assets, 'BUILD_STAMP.txt')
writeFileSync(out, `${stamp}\n`, 'utf8')
console.log(`Wrote ${out} (${stamp}, ${paths.length} files)`)
