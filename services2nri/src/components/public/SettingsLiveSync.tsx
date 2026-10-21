import { useEffect } from 'react'
import { useLocation } from 'react-router-dom'
import { isPublicMarketingPath, syncSettingsFromServer } from '@/lib/settings-live-sync'

/** Refreshes platform settings on public routes so CMS saves appear without a hard reload. */
export function SettingsLiveSync() {
  const { pathname } = useLocation()

  useEffect(() => {
    if (isPublicMarketingPath(pathname)) void syncSettingsFromServer()
  }, [pathname])

  return null
}
