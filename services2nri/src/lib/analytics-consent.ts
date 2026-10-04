/**
 * Load GA / Meta Pixel after cookie consent without a full page reload.
 */

function readSetting(key: string): string {
  const raw = window.S2NRI_CONFIG?.settings?.[key]
  if (raw == null) return ''
  if (typeof raw === 'object' && raw !== null && 'value' in (raw as object)) {
    return String((raw as { value: unknown }).value || '')
  }
  return String(raw)
}

export function loadConsentedAnalytics(): void {
  if (typeof document === 'undefined') return

  const gaId = readSetting('google_analytics_id')
  if (gaId && !document.querySelector(`script[data-s2nri-ga="${gaId}"]`)) {
    const gtag = document.createElement('script')
    gtag.async = true
    gtag.src = `https://www.googletagmanager.com/gtag/js?id=${encodeURIComponent(gaId)}`
    gtag.setAttribute('data-s2nri-ga', gaId)
    document.head.appendChild(gtag)
    const inline = document.createElement('script')
    inline.setAttribute('data-s2nri-ga-inline', gaId)
    inline.textContent =
      'window.dataLayer=window.dataLayer||[];function gtag(){dataLayer.push(arguments);}' +
      'gtag("js",new Date());gtag("config","' +
      gaId.replace(/\\/g, '\\\\').replace(/"/g, '\\"') +
      '");'
    document.head.appendChild(inline)
  }

  const fbPixel = readSetting('facebook_pixel_id')
  if (fbPixel && !(window as unknown as { fbq?: unknown }).fbq) {
    const inline = document.createElement('script')
    inline.setAttribute('data-s2nri-fbp', fbPixel)
    inline.textContent =
      '!function(f,b,e,v,n,t,s){if(f.fbq)return;n=f.fbq=function(){n.callMethod?' +
      'n.callMethod.apply(n,arguments):n.queue.push(arguments)};if(!f._fbq)f._fbq=n;' +
      'n.push=n;n.loaded=!0;n.version="2.0";n.queue=[];t=b.createElement(e);t.async=!0;' +
      't.src=v;s=b.getElementsByTagName(e)[0];s.parentNode.insertBefore(t,s)}' +
      '(window,document,"script","https://connect.facebook.net/en_US/fbevents.js");' +
      'fbq("init","' +
      fbPixel.replace(/\\/g, '\\\\').replace(/"/g, '\\"') +
      '");fbq("track","PageView");'
    document.head.appendChild(inline)
  }
}
