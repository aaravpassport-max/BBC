import { useState, useEffect, useCallback, useMemo } from 'react'
import api from '../../utils/api'
import { useToast } from '../../hooks/useToast'
import { ToastContainer, Button, FormGroup, Alert, Spinner } from '../../components/common'
import { useBuilderSticky } from '../../components/BuilderMobileUi'

/* ─────────────────────────────────────────────────────────────────────────────
 * Homepage Page Builder
 *
 * Architecture:
 *   - Content settings (text, images) → s2nri_settings → S2NRI_CONFIG → React Mn()
 *   - Visual settings (colors, sizes, spacing) → s2nri_settings['custom_css_homepage']
 *     → SEO.php injects <style id="s2nri-custom-css"> → overrides React styles
 *   - Section visibility → CSS in custom_css_homepage: .s2-stats-grid{display:none}
 *   - Live preview via iframe showing the actual homepage
 * ───────────────────────────────────────────────────────────────────────────── */

// CSS class map - maps section names to their actual CSS selectors in compiled Mn()
const HOMEPAGE_SECTIONS = [
  {
    id: 'stats',
    label: '📊 Stats Bar',
    description: 'Statistics counter below hero',
    toggleClass: 's2nri-hide-stats',
    contentFields: [
      { key: 'stat_1_number', label: 'Stat 1 Number', type: 'text', placeholder: '10,000+' },
      { key: 'stat_1_label',  label: 'Stat 1 Label',  type: 'text', placeholder: 'Happy Clients' },
      { key: 'stat_2_number', label: 'Stat 2 Number', type: 'text', placeholder: '44+' },
      { key: 'stat_2_label',  label: 'Stat 2 Label',  type: 'text', placeholder: 'Services' },
      { key: 'stat_3_number', label: 'Stat 3 Number', type: 'text', placeholder: '40+' },
      { key: 'stat_3_label',  label: 'Stat 3 Label',  type: 'text', placeholder: 'Locations' },
      { key: 'stat_4_number', label: 'Stat 4 Number', type: 'text', placeholder: '23+' },
      { key: 'stat_4_label',  label: 'Stat 4 Label',  type: 'text', placeholder: 'Countries Served' },
    ],
    styleFields: [
      { key: 'css_stats_bg',     label: 'Background Color',  css: '.s2-stats-grid,.s2-stats-grid~*{background:{{value}}!important}', type: 'color' },
      { key: 'css_stats_color',  label: 'Number Color',      css: '.s2-stats-grid .stat-num{color:{{value}}!important}', type: 'color' },
      { key: 'css_stats_padding',label: 'Section Padding',   css: '.s2-stats-grid{padding:{{value}}}', placeholder: '32px 20px' },
    ],
  },
  {
    id: 'tagline',
    label: '💬 Tagline Strip',
    description: 'Scrolling tagline / trust quote',
    contentFields: [
      { key: 'home_tagline', label: 'Tagline Text', type: 'textarea', placeholder: 'Forming strong and trusted connections with our clients' },
    ],
    styleFields: [
      { key: 'css_tagline_bg',    label: 'Background',  css: '.s2-hero-quote-wrap{background:{{value}}!important}', type: 'color' },
      { key: 'css_tagline_color', label: 'Text Color',  css: '.s2-hero-quote-wrap *{color:{{value}}!important}', type: 'color' },
      { key: 'css_tagline_size',  label: 'Font Size',   css: '.s2-hero-quote-wrap{font-size:{{value}}!important}', placeholder: '16px' },
    ],
  },
  {
    id: 'services',
    label: '🗂️ Services Grid',
    description: 'Service category tabs and service cards',
    styleFields: [
      { key: 'css_svc_cols',    label: 'Cards Per Row',   css: '.s2-svc-grid{grid-template-columns:repeat({{value}},1fr)!important}', placeholder: '3', hint: '2, 3, or 4' },
      { key: 'css_svc_bg',     label: 'Section BG',      css: '.s2-svc-grid{background:{{value}}!important}', type: 'color' },
      { key: 'css_svc_card_bg',label: 'Card Background', css: '.s2-svc-grid>*{background:{{value}}!important}', type: 'color' },
      { key: 'css_svc_gap',    label: 'Card Gap',        css: '.s2-svc-grid{gap:{{value}}!important}', placeholder: '24px' },
    ],
  },
  {
    id: 'how',
    label: '⚙️ How It Works',
    description: 'Step-by-step process section',
    styleFields: [
      { key: 'css_how_bg',    label: 'Section BG', css: '.s2-how-grid{background:{{value}}!important}', type: 'color' },
      { key: 'css_how_cols',  label: 'Columns',    css: '.s2-how-grid{grid-template-columns:repeat({{value}},1fr)!important}', placeholder: '4', hint: '2, 3, or 4' },
      { key: 'css_how_gap',   label: 'Gap',        css: '.s2-how-grid{gap:{{value}}!important}', placeholder: '32px' },
    ],
  },
  {
    id: 'global',
    label: '🎨 Global Styles',
    description: 'Branding, typography, and site-wide controls',
    contentFields: [
      { key: 'platform_name',     label: 'Platform Name',      type: 'text' },
      { key: 'platform_whatsapp', label: 'WhatsApp Number',    type: 'text' },
      { key: 'primary_color',     label: 'Primary Color',      type: 'color' },
      { key: 'accent_color',      label: 'Accent Color',       type: 'color' },
    ],
    styleFields: [
      { key: 'css_global_font',   label: 'Font Family',       css: 'body,#s2nri-root{font-family:{{value}},sans-serif!important}', placeholder: 'Inter' },
      { key: 'css_global_radius', label: 'Border Radius',     css: '#s2nri-root *{border-radius:{{value}}!important}', placeholder: '8px', hint: 'Applied globally to cards and buttons' },
      { key: 'css_global_maxw',   label: 'Content Max Width', css: '#s2nri-root .wrap,[class*="wrap"]{max-width:{{value}}!important;margin:0 auto}', placeholder: '1200px' },
    ],
  },
  {
    id: 'custom',
    label: '⌨️ Custom CSS',
    description: 'Write your own CSS for complete control',
    isCustom: true,
  },
]

// Section visibility CSS - hides entire sections by their rendered order/class
const SECTION_VISIBILITY_CSS = {
  hide_hero:     '#s2nri-root>div>section:nth-child(1){display:none!important}',
  hide_stats:    '.s2-stats-grid{display:none!important}',
  hide_tagline:  '.s2-hero-quote-wrap{display:none!important}',
  hide_services: '.s2-svc-grid,.s2-tabs{display:none!important}',
  hide_how:      '.s2-how-grid{display:none!important}',
}

export default function HomepageBuilderPage() {
  const [activeSection, setActiveSection] = useState('hero')
  const [settings, setSettings] = useState({})
  const [cssValues, setCssValues] = useState({})
  const [visibility, setVisibility] = useState({})
  const [customCss, setCustomCss] = useState('')
  const [loading, setLoading] = useState(true)
  const [saving, setSaving] = useState(false)
  const [dirty, setDirty] = useState(false)
  const [previewKey, setPreviewKey] = useState(0)
  const { toasts, toast } = useToast()

  const siteUrl = window.S2NRI_BUILDER?.siteUrl || ''

  useEffect(() => {
    api.get('admin/settings')
      .then(res => {
        const flat = {}
        Object.entries(res.settings || {}).forEach(([k, v]) => {
          flat[k] = typeof v === 'object' && v !== null && 'value' in v ? v.value : v
        })
        setSettings(flat)

        // Parse stored CSS values
        const cssVals = {}
        Object.entries(flat).forEach(([k, v]) => {
          if (k.startsWith('css_')) cssVals[k] = v
        })
        setCssValues(cssVals)

        // Parse visibility
        const vis = {}
        HOMEPAGE_SECTIONS.forEach(s => {
          const key = `hide_section_${s.id}`
          if (flat[key]) vis[s.id] = flat[key] === '1'
        })
        setVisibility(vis)

        setCustomCss(flat.custom_css_homepage || '')
      })
      .catch(err => toast.error(err.message))
      .finally(() => setLoading(false))
  }, [])

  function markDirty() { setDirty(true) }

  function updateSetting(key, value) {
    setSettings(s => ({ ...s, [key]: value }))
    markDirty()
  }

  function updateCss(key, value) {
    setCssValues(s => ({ ...s, [key]: value }))
    markDirty()
  }

  function toggleSection(sectionId, hidden) {
    setVisibility(v => ({ ...v, [sectionId]: hidden }))
    markDirty()
  }

  // Build the complete CSS from all style fields
  function buildCss() {
    let css = ''

    // Visibility toggles
    Object.entries(visibility).forEach(([sectionId, hidden]) => {
      if (hidden && SECTION_VISIBILITY_CSS[`hide_${sectionId}`]) {
        css += SECTION_VISIBILITY_CSS[`hide_${sectionId}`] + '\n'
      }
    })

    // Section style fields
    HOMEPAGE_SECTIONS.forEach(section => {
      if (!section.styleFields) return
      section.styleFields.forEach(field => {
        const val = cssValues[field.key]
        if (val && field.css) {
          css += field.css.replace(/{{value}}/g, val) + '\n'
        }
      })
    })

    // Custom CSS
    if (customCss) css += '\n' + customCss

    return css
  }

  async function save() {
    setSaving(true)
    try {
      // Build settings payload: content settings + CSS keys + visibility + generated CSS
      const payload = { ...settings }

      // CSS key values
      Object.entries(cssValues).forEach(([k, v]) => { payload[k] = v })

      // Visibility flags
      HOMEPAGE_SECTIONS.forEach(s => {
        payload[`hide_section_${s.id}`] = visibility[s.id] ? '1' : '0'
      })

      // The compiled custom CSS that gets injected by SEO.php
      payload['custom_css_homepage'] = buildCss()

      await api.put('admin/settings', payload)
      toast.success('Homepage saved — refresh the public site to see changes')
      setDirty(false)
      setPreviewKey(k => k + 1) // Reload preview iframe
    } catch (err) {
      toast.error(err.message || 'Save failed')
    } finally {
      setSaving(false)
    }
  }

  const section = HOMEPAGE_SECTIONS.find(s => s.id === activeSection)

  const saveSticky = useMemo(
    () => (
      <Button variant="primary" className="btn-lg s2-builder-sticky-primary" loading={saving} onClick={save} disabled={!dirty} style={{ width: '100%', justifyContent: 'center' }}>
        {dirty ? 'Save Homepage' : 'Saved ✓'}
      </Button>
    ),
    [dirty, saving, save],
  )
  useBuilderSticky(saveSticky)

  if (loading) return <Spinner />

  return (
    <>
      <ToastContainer toasts={toasts} />
      <div className="s2builder-home-grid" style={{ display: 'grid', gridTemplateColumns: '240px 1fr 480px', gap: 0, height: 'calc(100vh - 52px)', overflow: 'hidden' }}>

        {/* ── Left panel: section list ── */}
        <div style={{ borderRight: '1px solid var(--border)', overflowY: 'auto', background: 'var(--surface)' }}>
          <div style={{ padding: '16px', borderBottom: '1px solid var(--border)', display: 'flex', justifyContent: 'space-between', alignItems: 'center' }}>
            <h3 style={{ margin: 0, fontSize: 14, fontWeight: 700 }}>Homepage Sections</h3>
            <Button variant="primary" size="sm" className="s2-builder-header-save--desktop-only" loading={saving} onClick={save} disabled={!dirty}>
              {dirty ? 'Save' : '✓'}
            </Button>
          </div>
          {HOMEPAGE_SECTIONS.map(s => (
            <div
              key={s.id}
              style={{
                display: 'flex', alignItems: 'center', gap: 8, padding: '10px 16px',
                borderBottom: '1px solid var(--border)', cursor: 'pointer',
                background: activeSection === s.id ? 'var(--brand-light)' : 'transparent',
                borderLeft: `3px solid ${activeSection === s.id ? 'var(--brand)' : 'transparent'}`,
              }}
              onClick={() => setActiveSection(s.id)}
            >
              <span style={{ flex: 1, fontSize: 13, fontWeight: activeSection === s.id ? 700 : 500, color: activeSection === s.id ? 'var(--brand)' : 'var(--text)' }}>
                {s.label}
              </span>
              {/* Visibility toggle */}
              {s.id !== 'global' && s.id !== 'custom' && (
                <button
                  onClick={e => { e.stopPropagation(); toggleSection(s.id, !visibility[s.id]) }}
                  title={visibility[s.id] ? 'Hidden — click to show' : 'Visible — click to hide'}
                  style={{
                    border: 'none', background: 'none', cursor: 'pointer', padding: 2,
                    fontSize: 14, opacity: visibility[s.id] ? 0.4 : 1,
                  }}
                >
                  {visibility[s.id] ? '🙈' : '👁️'}
                </button>
              )}
            </div>
          ))}
        </div>

        {/* ── Middle panel: controls ── */}
        <div style={{ overflowY: 'auto', padding: 20, background: 'var(--surface-2)', borderRight: '1px solid var(--border)' }}>
          {dirty && (
            <Alert type="info" style={{ marginBottom: 16 }}>
              Unsaved changes. Click Save to apply to the live site.
            </Alert>
          )}

          {section && (
            <>
              <div style={{ marginBottom: 20 }}>
                <h2 style={{ margin: '0 0 4px', fontSize: 18, fontWeight: 800 }}>{section.label}</h2>
                <p style={{ margin: 0, fontSize: 13, color: 'var(--text-muted)' }}>{section.description}</p>
              </div>

              {/* Section visibility */}
              {section.id !== 'global' && section.id !== 'custom' && (
                <div style={{ display: 'flex', justifyContent: 'space-between', alignItems: 'center', padding: '12px 14px', background: 'var(--surface)', border: '1px solid var(--border)', borderRadius: 8, marginBottom: 20 }}>
                  <div>
                    <div style={{ fontWeight: 600, fontSize: 13 }}>Show this section</div>
                    <div style={{ fontSize: 11, color: 'var(--text-muted)' }}>Toggle visibility on the live homepage</div>
                  </div>
                  <button
                    onClick={() => toggleSection(section.id, !visibility[section.id])}
                    style={{
                      width: 44, height: 24, borderRadius: 99, border: 'none', cursor: 'pointer',
                      background: !visibility[section.id] ? 'var(--brand)' : 'var(--border)',
                      position: 'relative', transition: 'background 0.15s',
                    }}
                  >
                    <div style={{
                      position: 'absolute', top: 3, left: !visibility[section.id] ? 22 : 3,
                      width: 18, height: 18, borderRadius: '50%', background: '#fff',
                      transition: 'left 0.15s', boxShadow: '0 1px 3px rgba(0,0,0,.2)',
                    }} />
                  </button>
                </div>
              )}

              {/* Custom CSS editor */}
              {section.isCustom ? (
                <div>
                  <FormGroup label="Custom CSS" hint="Written directly into a <style> tag on the homepage. Use browser DevTools to find selectors.">
                    <textarea
                      className="form-textarea"
                      rows={20}
                      value={customCss}
                      onChange={e => { setCustomCss(e.target.value); markDirty() }}
                      placeholder="/* Example: hide the WhatsApp button */&#10;.wa-float { display: none !important; }&#10;&#10;/* Change hero heading size */&#10;#s2nri-root h1 { font-size: 56px !important; }"
                      style={{ fontFamily: 'monospace', fontSize: 12 }}
                    />
                  </FormGroup>
                </div>
              ) : (
                <>
                  {/* Content fields */}
                  {section.contentFields && section.contentFields.length > 0 && (
                    <div className="card" style={{ marginBottom: 16 }}>
                      <div className="card-header">
                        <h4 className="card-title" style={{ fontSize: 13 }}>✏️ Content</h4>
                      </div>
                      <div className="card-body">
                        {section.contentFields.map(field => (
                          <FormGroup key={field.key} label={field.label} hint={field.hint}>
                            {field.type === 'textarea' ? (
                              <textarea className="form-textarea" rows={3} value={settings[field.key] || ''} onChange={e => updateSetting(field.key, e.target.value)} placeholder={field.placeholder || ''} />
                            ) : field.type === 'color' ? (
                              <div style={{ display: 'flex', gap: 8 }}>
                                <input type="color" value={settings[field.key] || '#4A6FA5'} onChange={e => updateSetting(field.key, e.target.value)} style={{ width: 40, height: 36, border: '1px solid var(--border)', borderRadius: 6, cursor: 'pointer', padding: 2 }} />
                                <input type="text" className="form-input" value={settings[field.key] || ''} onChange={e => updateSetting(field.key, e.target.value)} placeholder={field.placeholder || '#4A6FA5'} />
                              </div>
                            ) : (
                              <input type="text" className="form-input" value={settings[field.key] || ''} onChange={e => updateSetting(field.key, e.target.value)} placeholder={field.placeholder || ''} />
                            )}
                          </FormGroup>
                        ))}
                      </div>
                    </div>
                  )}

                  {/* Style fields */}
                  {section.styleFields && section.styleFields.length > 0 && (
                    <div className="card">
                      <div className="card-header">
                        <h4 className="card-title" style={{ fontSize: 13 }}>🎨 Styles</h4>
                      </div>
                      <div className="card-body">
                        <Alert type="info" style={{ marginBottom: 16, fontSize: 12 }}>
                          Style changes inject CSS directly onto the page, overriding compiled styles.
                        </Alert>
                        {section.styleFields.map(field => (
                          <FormGroup key={field.key} label={field.label} hint={field.hint}>
                            {field.type === 'color' ? (
                              <div style={{ display: 'flex', gap: 8 }}>
                                <input type="color" value={cssValues[field.key] || '#000000'} onChange={e => updateCss(field.key, e.target.value)} style={{ width: 40, height: 36, border: '1px solid var(--border)', borderRadius: 6, cursor: 'pointer', padding: 2 }} />
                                <input type="text" className="form-input" value={cssValues[field.key] || ''} onChange={e => updateCss(field.key, e.target.value)} placeholder={field.placeholder || ''} />
                              </div>
                            ) : (
                              <input type="text" className="form-input" value={cssValues[field.key] || ''} onChange={e => updateCss(field.key, e.target.value)} placeholder={field.placeholder || ''} />
                            )}
                          </FormGroup>
                        ))}
                      </div>
                    </div>
                  )}
                </>
              )}
            </>
          )}
        </div>

        {/* ── Right panel: live preview ── */}
        <div style={{ display: 'flex', flexDirection: 'column', overflow: 'hidden' }}>
          <div style={{ padding: '10px 16px', borderBottom: '1px solid var(--border)', background: 'var(--surface)', display: 'flex', alignItems: 'center', justifyContent: 'space-between', flexShrink: 0 }}>
            <span style={{ fontSize: 13, fontWeight: 600 }}>Live Preview</span>
            <div style={{ display: 'flex', gap: 8 }}>
              <button onClick={() => setPreviewKey(k => k + 1)} style={{ fontSize: 12, border: '1px solid var(--border)', background: 'var(--surface)', padding: '4px 10px', borderRadius: 6, cursor: 'pointer' }}>
                ↻ Refresh
              </button>
              <a href={siteUrl} target="_blank" rel="noreferrer" style={{ fontSize: 12, border: '1px solid var(--border)', background: 'var(--surface)', padding: '4px 10px', borderRadius: 6, cursor: 'pointer', textDecoration: 'none', color: 'var(--text)' }}>
                ↗ Open
              </a>
            </div>
          </div>
          {siteUrl ? (
            <iframe
              key={previewKey}
              src={siteUrl}
              style={{ flex: 1, border: 'none', width: '100%' }}
              title="Homepage preview"
            />
          ) : (
            <div style={{ flex: 1, display: 'flex', alignItems: 'center', justifyContent: 'center', color: 'var(--text-muted)', fontSize: 13 }}>
              Preview unavailable — site URL not configured
            </div>
          )}
        </div>
      </div>
    </>
  )
}
