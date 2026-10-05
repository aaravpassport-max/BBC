/**
 * In-memory + sessionStorage cache with in-flight deduplication (stale-while-revalidate).
 */

const MEMORY = new Map<string, { data: unknown; ts: number }>()
const INFLIGHT = new Map<string, Promise<unknown>>()
const SUBSCRIBERS = new Map<string, Set<(data: unknown) => void>>()

const PERSIST_PREFIX = 's2nri_res:'
/** Within this window, GET returns cache only — no network round-trip. */
export const DEFAULT_FRESH_TTL_MS = 45_000
const DEFAULT_STALE_TTL_MS = 15 * 60_000
const MAX_PERSIST_BYTES = 512_000

export type FetchResourceOptions = {
  /** Max age before a background revalidate is scheduled (default 45s). */
  ttl?: number
  /** Hard cap — after this, cache is ignored and fetch blocks (default 15m). */
  staleTtl?: number
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

function entryAge(key: string): number | null {
  const mem = MEMORY.get(key)
  if (mem) return Date.now() - mem.ts
  const persisted = readPersisted(key)
  if (persisted) return Date.now() - persisted.ts
  return null
}

function notifySubscribers(key: string, data: unknown): void {
  SUBSCRIBERS.get(key)?.forEach((fn) => {
    try {
      fn(data)
    } catch {
      /* ignore listener errors */
    }
  })
}

export function subscribeResource(method: string, path: string, listener: (data: unknown) => void): () => void {
  const key = cacheKey(method, path)
  if (!SUBSCRIBERS.has(key)) SUBSCRIBERS.set(key, new Set())
  SUBSCRIBERS.get(key)!.add(listener)
  return () => {
    SUBSCRIBERS.get(key)?.delete(listener)
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
  notifySubscribers(key, data)
}

export function invalidateCache(prefix?: string): void {
  if (!prefix) {
    MEMORY.clear()
    return
  }
  for (const k of MEMORY.keys()) {
    if (k.includes(prefix)) MEMORY.delete(k)
  }
  try {
    for (let i = sessionStorage.length - 1; i >= 0; i--) {
      const k = sessionStorage.key(i)
      if (k?.startsWith(PERSIST_PREFIX) && k.includes(prefix)) {
        sessionStorage.removeItem(k)
      }
    }
  } catch {
    /* ignore */
  }
}

function runNetworkFetch<T>(
  key: string,
  method: string,
  path: string,
  fetcher: () => Promise<T>,
  persist: boolean,
): Promise<T> {
  const existing = INFLIGHT.get(key)
  if (existing) return existing as Promise<T>

  const run = fetcher()
    .then((data) => {
      writeCache(method, path, data, persist)
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

function scheduleBackgroundRevalidate<T>(
  key: string,
  method: string,
  path: string,
  fetcher: () => Promise<T>,
  persist: boolean,
): void {
  if (INFLIGHT.has(key)) return
  void runNetworkFetch(key, method, path, fetcher, persist).catch(() => {})
}

export async function fetchResource<T>(
  method: string,
  path: string,
  fetcher: () => Promise<T>,
  options: FetchResourceOptions = {},
): Promise<T> {
  const key = cacheKey(method, path)
  const freshTtl = options.ttl ?? DEFAULT_FRESH_TTL_MS
  const staleTtl = options.staleTtl ?? DEFAULT_STALE_TTL_MS
  const persist = options.persist ?? true
  const cached = peekCached<T>(method, path)
  const age = entryAge(key)

  if (!options.force && cached !== undefined && age !== null) {
    if (age < freshTtl) {
      return cached
    }
    if (age < staleTtl) {
      scheduleBackgroundRevalidate(key, method, path, fetcher, persist)
      return cached
    }
  }

  return runNetworkFetch(key, method, path, fetcher, persist)
}

export function isCacheFresh(method: string, path: string, ttl = DEFAULT_FRESH_TTL_MS): boolean {
  const key = cacheKey(method, path)
  const age = entryAge(key)
  return age !== null && age < ttl
}

export function apiCacheKey(path: string): string {
  return cacheKey('GET', path)
}
