/**
 * In-memory + sessionStorage cache with in-flight deduplication (stale-while-revalidate).
 */

const MEMORY = new Map<string, { data: unknown; ts: number }>()
const INFLIGHT = new Map<string, Promise<unknown>>()

const PERSIST_PREFIX = 's2nri_res:'
const DEFAULT_TTL_MS = 120_000
const MAX_PERSIST_BYTES = 512_000

export type FetchResourceOptions = {
  ttl?: number
  /** Persist GET payloads in sessionStorage for instant revisits this session */
  persist?: boolean
  force?: boolean
}

function cacheKey(method: string, path: string): string {
  return `${method}:${path.replace(/\s+/g, '')}`
}

function readPersisted<T>(key: string): { data: T; ts: number } | null {
  try {
    const raw = sessionStorage.getItem(PERSIST_PREFIX + key)
    if (!raw) return null
    const parsed = JSON.parse(raw) as { data: T; ts: number }
    if (!parsed || typeof parsed.ts !== 'number') return null
    return parsed
  } catch {
    return null
  }
}

function writePersisted(key: string, data: unknown, ts: number): void {
  try {
    const payload = JSON.stringify({ data, ts })
    if (payload.length > MAX_PERSIST_BYTES) return
    sessionStorage.setItem(PERSIST_PREFIX + key, payload)
  } catch {
    /* quota / private mode */
  }
}

export function peekCached<T>(method: string, path: string): T | undefined {
  const key = cacheKey(method, path)
  const mem = MEMORY.get(key)
  if (mem) return mem.data as T
  const persisted = readPersisted<T>(key)
  if (persisted) {
    MEMORY.set(key, { data: persisted.data, ts: persisted.ts })
    return persisted.data
  }
  return undefined
}

export function writeCache(method: string, path: string, data: unknown, persist = false): void {
  const key = cacheKey(method, path)
  const ts = Date.now()
  MEMORY.set(key, { data, ts })
  if (persist) writePersisted(key, data, ts)
}

export function invalidateCache(prefix?: string): void {
  if (!prefix) {
    MEMORY.clear()
    return
  }
  for (const k of MEMORY.keys()) {
    if (k.includes(prefix)) MEMORY.delete(k)
  }
}

export async function fetchResource<T>(
  method: string,
  path: string,
  fetcher: () => Promise<T>,
  options: FetchResourceOptions = {},
): Promise<T> {
  const key = cacheKey(method, path)
  const ttl = options.ttl ?? DEFAULT_TTL_MS

  if (!options.force) {
    const existing = INFLIGHT.get(key)
    if (existing) return existing as Promise<T>
  }

  const run = fetcher()
    .then((data) => {
      writeCache(method, path, data, options.persist)
      INFLIGHT.delete(key)
      return data
    })
    .catch((err) => {
      INFLIGHT.delete(key)
      throw err
    })

  INFLIGHT.set(key, run)
  return run
}

export function isCacheFresh(method: string, path: string, ttl = DEFAULT_TTL_MS): boolean {
  const key = cacheKey(method, path)
  const mem = MEMORY.get(key)
  if (mem && Date.now() - mem.ts < ttl) return true
  const persisted = readPersisted(key)
  return !!(persisted && Date.now() - persisted.ts < ttl)
}

export function apiCacheKey(path: string): string {
  return cacheKey('GET', path)
}
