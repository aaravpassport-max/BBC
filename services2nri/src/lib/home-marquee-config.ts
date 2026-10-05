/**
 * Homepage announcement marquee — merges JSON config with legacy notice/marquee settings.
 */

export type HomeMarqueeItem = {
  text: string
  link?: string
  target?: '_self' | '_blank'
  icon?: string
}

export type HomeMarqueeConfig = {
  enabled: boolean
  static: boolean
  direction: 'ltr' | 'rtl'
  speedSec: number
  pauseOnHover: boolean
  showControls: boolean
  separator: string
  bg: string
  color: string
  fontSize: string
  fontWeight: string
  padding: string
  borderBottom: string
  hideMobile: boolean
  hideDesktop: boolean
  items: HomeMarqueeItem[]
}

function flag(raw: string | undefined, defaultOn = true): boolean {
  if (raw === undefined || raw === '') return defaultOn
  return raw !== '0'
}

function parseItemsJson(raw: string | undefined): HomeMarqueeItem[] | null {
  if (!raw?.trim()) return null
  try {
    const parsed = JSON.parse(raw) as unknown
    if (!Array.isArray(parsed)) return null
    return parsed
      .map((row) => {
        if (typeof row === 'string') return { text: row }
        if (row && typeof row === 'object') {
          const o = row as Record<string, unknown>
          const text = String(o.text ?? o.message ?? '').trim()
          if (!text) return null
          return {
            text,
            link: o.link ? String(o.link) : o.url ? String(o.url) : undefined,
            target: o.target === '_blank' ? '_blank' : '_self',
            icon: o.icon ? String(o.icon) : undefined,
          } satisfies HomeMarqueeItem
        }
        return null
      })
      .filter(Boolean) as HomeMarqueeItem[]
  } catch {
    return null
  }
}

type MarqueeJsonRoot = {
  enabled?: boolean
  static?: boolean
  direction?: string
  speed?: number | string
  pauseOnHover?: boolean
  showControls?: boolean
  separator?: string
  bg?: string
  color?: string
  fontSize?: string
  fontWeight?: string
  padding?: string
  borderBottom?: string
  hideMobile?: boolean
  hideDesktop?: boolean
  items?: HomeMarqueeItem[]
}

function parseConfigJson(raw: string | undefined): Partial<MarqueeJsonRoot> | null {
  if (!raw?.trim()) return null
  try {
    const parsed = JSON.parse(raw) as unknown
    if (!parsed || typeof parsed !== 'object' || Array.isArray(parsed)) return null
    return parsed as MarqueeJsonRoot
  } catch {
    return null
  }
}

/** Build runtime marquee config from platform settings. */
export function resolveHomeMarquee(settings: Record<string, string>): HomeMarqueeConfig | null {
  const root = parseConfigJson(settings.home_marquee_json)
  const jsonItems = root?.items?.length ? root.items : parseItemsJson(settings.home_marquee_items_json)

  const legacyText = settings.home_notice_text?.trim() || settings.marquee_text?.trim()
  const whatsapp = settings.platform_whatsapp
  const items: HomeMarqueeItem[] =
    jsonItems?.length
      ? jsonItems
      : legacyText
        ? [
            { text: legacyText, icon: '📢' },
            ...(whatsapp && settings.home_notice_whatsapp_label
              ? [{
                  text: settings.home_notice_whatsapp_label,
                  link: `https://wa.me/${String(whatsapp).replace(/\D/g, '')}`,
                  target: '_blank' as const,
                  icon: '💬',
                }]
              : []),
          ]
        : []

  if (!items.length) return null

  const enabled =
    root?.enabled ??
    (settings.marquee_show !== undefined ? flag(settings.marquee_show, true) : true)

  if (!enabled) return null

  return {
    enabled: true,
    static: root?.static ?? settings.marquee_static === '1',
    direction:
      root?.direction === 'rtl' || settings.marquee_direction === 'rtl' ? 'rtl' : 'ltr',
    speedSec: Math.max(8, Number(root?.speed ?? settings.marquee_speed) || 36),
    pauseOnHover: root?.pauseOnHover ?? flag(settings.marquee_pause_hover, true),
    showControls: root?.showControls ?? settings.marquee_show_controls === '1',
    separator: root?.separator ?? settings.marquee_separator ?? '·',
    bg: root?.bg ?? settings.marquee_bg ?? settings.css_notice_bg ?? 'var(--s2-color-secondary, #1E2D40)',
    color: root?.color ?? settings.marquee_color ?? settings.css_notice_color ?? '#ffffff',
    fontSize: root?.fontSize ?? settings.marquee_font_size ?? '0.875rem',
    fontWeight: root?.fontWeight ?? settings.marquee_font_weight ?? '600',
    padding: root?.padding ?? settings.marquee_padding ?? '11px 0',
    borderBottom: root?.borderBottom ?? settings.marquee_border ?? '1px solid rgba(255,255,255,0.12)',
    hideMobile: root?.hideMobile ?? settings.marquee_hide_mobile === '1',
    hideDesktop: root?.hideDesktop ?? settings.marquee_hide_desktop === '1',
    items,
  }
}
