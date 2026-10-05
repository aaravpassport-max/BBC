import { useCallback, useEffect, useRef, useState } from 'react'
import { fetchResource, peekCached, type FetchResourceOptions } from '@/lib/resource-cache'

export type UseResourceResult<T> = {
  data: T | undefined
  error: boolean
  /** True only when there is no cached/previous data yet */
  isInitialLoad: boolean
  isRefreshing: boolean
  refresh: (force?: boolean) => void
}

export function useResource<T>(
  path: string | null,
  fetcher: () => Promise<T>,
  options: FetchResourceOptions & { enabled?: boolean } = {},
): UseResourceResult<T> {
  const enabled = (options.enabled !== false) && !!path
  const cachePath = path || ''

  const [data, setData] = useState<T | undefined>(() =>
    enabled ? peekCached<T>('GET', cachePath) : undefined,
  )
  const [error, setError] = useState(false)
  const [isRefreshing, setIsRefreshing] = useState(false)
  const mounted = useRef(true)

  const revalidate = useCallback(
    async (background: boolean, force = false) => {
      if (!enabled || !path) return
      if (!background) setError(false)
      if (background && data !== undefined) setIsRefreshing(true)
      try {
        const fresh = await fetchResource<T>('GET', path, fetcher, { ...options, force })
        if (mounted.current) {
          setData(fresh)
          setError(false)
        }
      } catch {
        if (mounted.current && data === undefined) setError(true)
      } finally {
        if (mounted.current) setIsRefreshing(false)
      }
    },
    [enabled, path, fetcher, data, options.ttl, options.persist],
  )

  useEffect(() => {
    mounted.current = true
    if (!enabled || !path) {
      setData(undefined)
      return () => {
        mounted.current = false
      }
    }

    const cached = peekCached<T>('GET', path)
    setData(cached)
    setError(false)
    if (cached !== undefined) {
      void revalidate(true, false)
    } else {
      void revalidate(false, false)
    }

    return () => {
      mounted.current = false
    }
  }, [enabled, path])

  const refresh = useCallback(
    (force = true) => {
      void revalidate(false, force)
    },
    [revalidate],
  )

  return {
    data,
    error,
    isInitialLoad: enabled && data === undefined && !error,
    isRefreshing,
    refresh,
  }
}
