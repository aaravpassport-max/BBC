import type React from 'react'

/** Setting keys for top/bottom padding per breakpoint (falls back to legacy single key). */
export function paddingKeys(base: string) {
  return {
    legacy: base,
    desktop: `${base}_desktop`,
    tablet: `${base}_tablet`,
    mobile: `${base}_mobile`,
  }
}

export const RESPONSIVE_PAD_CLASS = 's2-home-band--responsive-pad'

export function bandPadClass(settings: Record<string, string>, paddingBase?: string): string {
  if (!paddingBase) return ''
  const k = paddingKeys(paddingBase)
  if (settings[k.desktop] || settings[k.tablet] || settings[k.mobile]) {
    return RESPONSIVE_PAD_CLASS
  }
  return ''
}

export function applyResponsivePadding(
  settings: Record<string, string>,
  paddingBase: string,
  style: React.CSSProperties,
): void {
  const k = paddingKeys(paddingBase)
  const legacy = settings[k.legacy] || ''
  const desktop = settings[k.desktop] || legacy
  const tablet = settings[k.tablet] || legacy
  const mobile = settings[k.mobile] || legacy
  if (!desktop && !tablet && !mobile) return
  const s = style as Record<string, string | undefined>
  s['--s2-pad-desktop'] = desktop || undefined
  s['--s2-pad-tablet'] = tablet || undefined
  s['--s2-pad-mobile'] = mobile || undefined
  s.padding = mobile || tablet || desktop
}

export function pickCssStyle(
  settings: Record<string, string>,
  keys: { bg?: string; padding?: string; color?: string },
): React.CSSProperties | undefined {
  const style: React.CSSProperties = {}
  if (keys.bg && settings[keys.bg]) style.background = settings[keys.bg]
  if (keys.padding) applyResponsivePadding(settings, keys.padding, style)
  if (keys.color && settings[keys.color]) style.color = settings[keys.color]
  return Object.keys(style).length ? style : undefined
}
