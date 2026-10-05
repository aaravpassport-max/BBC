import { useEffect } from 'react'
import { useLocation } from 'react-router-dom'
import { installDesignLiveSync, isAdminDesignEditorPath, syncDesignFromServer } from '@/lib/design-live-sync'

/** Keeps public (and portal) design tokens in sync with server after admin publish/preset. */
export function DesignLiveSync() {
  const { pathname } = useLocation()

  useEffect(() => installDesignLiveSync(), [])

  useEffect(() => {
    if (!isAdminDesignEditorPath(pathname)) void syncDesignFromServer()
  }, [pathname])

  return null
}
