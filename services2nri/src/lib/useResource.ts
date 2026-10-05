import { useCallback, useEffect, useRef, useState } from 'react'
import {
  fetchResource,
  peekCached,
  subscribeResource,
  type FetchResourceOptions,
} from '@/lib/resource-cache'

export type UseResourceResult<T> = {
  data: T | undefined
  error: boolean
  isInitialLoad: boolean
  isRefreshing: boolean
  refresh: (force?: boolean) => void
}

export function useResource<T>(
  path: string | null,
  fetcher: () => Promise<T>,
  options: FetchResourceOptions & { enabled?: boolean } = {},
): UseResourceResult<T> {
  const enabled = options.enabled !== false && !!path
  const cachePath = path || ''

  const [data, setData] = useState<T | undefined>(() =>
    enabled ? peekCached<T>('GET', cachePath) : undefined,
  )
  const [error, setError] = useState(false)
  const [isRefreshing, setIsRefreshing] = useState(false)
  const mounted = useRef(true)
  const refreshTimer = useRef<number | null>(null)
  const fetcherRef = useRef(fetcher)
  const optionsRef = useRef(options)
  fetcherRef.current = fetcher
  optionsRef.current = options

  const setRefreshingDebounced = useCallback((on: boolean) => {
    if (refreshTimer.current) {
      window.clearTimeout(refreshTimer.current)
      refreshTimer.current = null
    }
    if (on) {
      refreshTimer.current = window.setTimeout(() => {
        if (mounted.current) setIsRefreshing(true)
      }, 350)
    } else {
      setIsRefreshing(false)
    }
  }, [])

  const revalidate = useCallback(async (force = false) => {
    const p = cachePath
    if (!enabled || !p) return
    const hadData = peekCached<T>('GET', p) !== undefined
    if (!hadData) setError(false)
    if (hadData && !force) setRefreshingDebounced(true)
    try {
      const fresh = await fetchResource<T>('GET', p, () => fetcherRef.current(), {
        ...optionsRef.current,
        force,
      })
      if (mounted.current) {
        setData(fresh)
        setError(false)
      }
    } catch {
      if (mounted.current && !hadData) setError(true)
    } finally {
      if (mounted.current) setRefreshingDebounced(false)
    }
  }, [enabled, cachePath, setRefreshingDebounced])

  useEffect(() => {
    mounted.current = true
    if (!enabled || !path) {
      setData(undefined)
      return () => {
        mounted.current = false
      }
    }

    setData(peekCached<T>('GET', path))
    setError(false)
    void revalidate(false)

    const unsub = subscribeResource('GET', path, (fresh) => {
      if (mounted.current) setData(fresh as T)
    })

    return () => {
      mounted.current = false
      unsub()
      if (refreshTimer.current) window.clearTimeout(refreshTimer.current)
    }
  }, [enabled, path, revalidate])

  const refresh = useCallback(
    (force = true) => {
      void revalidate(force)
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
