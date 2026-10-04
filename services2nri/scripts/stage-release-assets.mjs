#!/usr/bin/env node
/**
 * Copy canonical assets/ into assets/release/{BUILD_STAMP}/ so CDN caches by
 * unique path (Cloudflare often ignores ?v= on /chunks/booking.js).
 */
import { cpSync, existsSync, mkdirSync, readFileSync, readdirSync, rmSync } from 'node:fs'
import { join, dirname } from 'node:path'
import { fileURLToPath } from 'node:url'

const root = join(dirname(fileURLToPath(import.meta.url)), '..')
const assets = join(root, 'assets')
const stamp = readFileSync(join(assets, 'BUILD_STAMP.txt'), 'utf8').trim()
const releaseRoot = join(assets, 'release')
const dest = join(releaseRoot, stamp)

const toCopy = [
  'app.js',
  'app.css',
  'boot-config.js',
  'boot-watchdog.js',
  'boot-sw-cleanup.js',
]

rmSync(releaseRoot, { recursive: true, force: true })
mkdirSync(join(dest, 'chunks'), { recursive: true })

for (const f of toCopy) {
  const src = join(assets, f)
  if (!existsSync(src)) throw new Error(`Missing ${f} for release staging`)
  cpSync(src, join(dest, f))
}

const chunksDir = join(assets, 'chunks')
for (const name of readdirSync(chunksDir).filter((f) => f.endsWith('.js'))) {
  cpSync(join(chunksDir, name), join(dest, 'chunks', name))
}

console.log(`Staged release assets → assets/release/${stamp}/`)
