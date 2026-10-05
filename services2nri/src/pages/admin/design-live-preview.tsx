/**
 * Embedded public route preview for the Design System builder (Phase 11).
 */
import React, { useCallback, useEffect, useMemo, useRef } from 'react'
import {
  PLATFORM_SETTINGS_PREVIEW_MSG,
  buildDesignPreviewUrl,
} from '@/lib/platform-settings-preview'

export function DesignLivePreview({
  path,
  reloadToken,
  draftSettings,
  pageLabel,
}: {
  path: string
  reloadToken: number
  draftSettings: Record<string, string>
  pageLabel: string
}) {
  const iframeRef = useRef<HTMLIFrameElement>(null)
  const src = useMemo(() => buildDesignPreviewUrl(path, reloadToken), [path, reloadToken])

  const pushDraftToFrame = useCallback(() => {
    const frame = iframeRef.current?.contentWindow
    if (!frame) return
    frame.postMessage(
      { type: PLATFORM_SETTINGS_PREVIEW_MSG, settings: draftSettings },
      window.location.origin,
    )
  }, [draftSettings])

  useEffect(() => {
    pushDraftToFrame()
  }, [pushDraftToFrame])

  const refresh = () => {
    const el = iframeRef.current
    if (!el) return
    el.src = buildDesignPreviewUrl(path, Date.now())
  }

  return (
    <aside className="s2-design-live-preview" aria-label="Live page preview">
      <div className="s2-design-live-preview__head">
        <div>
          <p className="s2-design-live-preview__eyebrow">Live preview</p>
          <p className="s2-design-live-preview__path">{path}</p>
        </div>
        <div className="s2-design-live-preview__actions">
          <button type="button" className="s2-btn s2-btn--ghost s2-btn--sm" onClick={refresh}>
            Refresh
          </button>
          <a
            href={path}
            target="_blank"
            rel="noopener noreferrer"
            className="s2-btn s2-btn--outline s2-btn--sm"
          >
            Open ↗
          </a>
        </div>
      </div>
      <p className="s2-design-live-preview__hint">
        Draft edits sync into this frame while you type; saving reloads <strong>{pageLabel}</strong> from the
        server.
      </p>
      <div className="s2-design-live-preview__frame-wrap">
        <iframe
          ref={iframeRef}
          key={src}
          title={`Preview ${pageLabel}`}
          src={src}
          className="s2-design-live-preview__frame"
          data-testid="design-live-preview-frame"
          onLoad={pushDraftToFrame}
        />
      </div>
    </aside>
  )
}
