import { useState, useEffect, useCallback } from 'react'
import api from '../utils/api'

export function useApi(path, params = {}, deps = []) {
  const [data, setData] = useState(null)
  const [loading, setLoading] = useState(true)
  const [error, setError] = useState(null)

  const load = useCallback(async () => {
    setLoading(true)
    setError(null)
    try {
      const res = await api.get(path, params)
      setData(res)
    } catch (err) {
      setError(err.message || 'Failed to load data')
    } finally {
      setLoading(false)
    }
  }, [path, JSON.stringify(params), ...deps])

  useEffect(() => { load() }, [load])

  return { data, loading, error, reload: load, setData }
}
