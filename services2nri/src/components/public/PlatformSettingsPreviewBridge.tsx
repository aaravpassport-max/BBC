import { useEffect } from 'react'
import { installPlatformSettingsPreviewListener } from '@/lib/platform-settings-preview'

/** Applies draft CMS settings postMessage'd from /admin/design live preview iframe. */
export function PlatformSettingsPreviewBridge() {
  useEffect(() => installPlatformSettingsPreviewListener(), [])
  return null
}
