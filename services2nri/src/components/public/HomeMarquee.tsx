import React, { useMemo, useState } from 'react'
import { cssVars } from '@/lib/design-tokens'
import { resolveHomeMarquee, type HomeMarqueeItem } from '@/lib/home-marquee-config'
import { CmsElement } from '@/components/public/CmsElement'

function isWhatsappLink(item: HomeMarqueeItem, whatsapp?: string): boolean {
  if (!item.link || !whatsapp) return false
  const digits = String(whatsapp).replace(/\D/g, '')
  return digits.length > 0 && item.link.replace(/\D/g, '').includes(digits)
}

function renderItem(item: HomeMarqueeItem, key: string, whatsapp?: string) {
  const inner = (
    <>
      {item.icon ? <span className="s2-home-marquee__icon" aria-hidden>{item.icon}</span> : null}
      <span>{item.text}</span>
    </>
  )

  if (item.link) {
    const link = (
      <a
        href={item.link}
        target={item.target === '_blank' ? '_blank' : undefined}
        rel={item.target === '_blank' ? 'noopener noreferrer' : undefined}
        className="s2-home-marquee__item s2-home-marquee__link"
      >
        {inner}
      </a>
    )
    if (isWhatsappLink(item, whatsapp)) {
      return (
        <CmsElement key={key} pageId="home" sectionKey="notice" elementId="link" className="s2-home-marquee__item-wrap">
          {link}
        </CmsElement>
      )
    }
    return (
      <span key={key} className="s2-home-marquee__item-wrap">
        {link}
      </span>
    )
  }

  return (
    <span key={key} className="s2-home-marquee__item">
      {inner}
    </span>
  )
}

export function HomeMarquee({
  settings,
  whatsapp,
}: {
  settings: Record<string, string>
  whatsapp?: string
}) {
  const config = useMemo(() => resolveHomeMarquee(settings), [settings])
  const [paused, setPaused] = useState(false)

  if (!config) return null

  const sequence = config.items.flatMap((item, i) => [
    renderItem(item, `a-${i}`, whatsapp),
    <span key={`sep-a-${i}`} className="s2-home-marquee__sep" aria-hidden>{config.separator}</span>,
  ])

  const trackContent = (
    <>
      {sequence}
      {sequence}
    </>
  )

  const style = cssVars({
    's2-home-marquee-bg': config.bg,
    's2-home-marquee-fg': config.color,
    's2-home-marquee-duration': `${config.speedSec}s`,
    's2-home-marquee-pad': config.padding,
    's2-home-marquee-border': config.borderBottom,
    's2-home-marquee-fs': config.fontSize,
    's2-home-marquee-fw': config.fontWeight,
  })

  const className = [
    's2-home-marquee',
    config.static ? 's2-home-marquee--static' : '',
    config.direction === 'rtl' ? 's2-home-marquee--rtl' : '',
    config.hideMobile ? 's2-home-marquee--hide-mobile' : '',
    config.hideDesktop ? 's2-home-marquee--hide-desktop' : '',
    paused ? 'is-paused' : '',
  ]
    .filter(Boolean)
    .join(' ')

  return (
    <div
      className={className}
      data-s2-section="notice"
      style={style}
      onMouseEnter={config.pauseOnHover ? () => setPaused(true) : undefined}
      onMouseLeave={config.pauseOnHover ? () => setPaused(false) : undefined}
    >
      {config.showControls && !config.static ? (
        <button
          type="button"
          className="s2-home-marquee__toggle"
          aria-label={paused ? 'Play announcement ticker' : 'Pause announcement ticker'}
          onClick={() => setPaused((p) => !p)}
        >
          {paused ? '▶' : '❚❚'}
        </button>
      ) : null}
      <CmsElement pageId="home" sectionKey="notice" elementId="body" className="s2-home-marquee__viewport">
        <div className={`s2-home-marquee__track${config.static ? ' s2-home-marquee__track--static' : ''}`}>
          {config.static ? (
            <div className="s2-home-marquee__static-row">
              {config.items.map((item, i) => renderItem(item, `s-${i}`, whatsapp))}
            </div>
          ) : (
            trackContent
          )}
        </div>
      </CmsElement>
    </div>
  )
}
