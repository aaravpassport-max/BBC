import { useEffect } from 'react'

/** SPA routes: sync document title + meta description from platform settings. */
export function usePageDocumentMeta(
  settings: Record<string, string>,
  opts: { titleKey?: string; descriptionKey?: string; titleFallback?: string; descriptionFallback?: string },
) {
  const { titleKey, descriptionKey, titleFallback, descriptionFallback } = opts

  useEffect(() => {
    const title =
      (titleKey && settings[titleKey]?.trim()) ||
      titleFallback ||
      settings.seo_title ||
      settings.platform_name ||
      document.title
    if (title) document.title = title

    const desc =
      (descriptionKey && settings[descriptionKey]?.trim()) ||
      descriptionFallback ||
      settings.seo_description
    if (!desc) return

    let meta = document.querySelector('meta[name="description"]') as HTMLMetaElement | null
    if (!meta) {
      meta = document.createElement('meta')
      meta.name = 'description'
      document.head.appendChild(meta)
    }
    meta.content = desc
  }, [settings, titleKey, descriptionKey, titleFallback, descriptionFallback])
}
