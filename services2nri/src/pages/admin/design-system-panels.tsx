/**
 * Sub-panels for the centralized Design & Service Registry admin UI.
 */
import React from 'react'

type PatchFn = (path: string[], value: unknown) => void

const TYPO_ROLES = [
  'page_title', 'h1', 'h2', 'h3', 'section_heading', 'section_subheading', 'eyebrow',
  'body', 'body_lg', 'body_sm', 'caption', 'nav', 'button', 'card_title', 'card_desc',
  'label', 'breadcrumb', 'badge', 'cta_heading', 'footer_heading',
] as const

export function TypographyRolesEditor({
  typography,
  patch,
}: {
  typography: Record<string, Record<string, string>>
  patch: PatchFn
}) {
  return (
    <div style={{ display: 'flex', flexDirection: 'column', gap: 20 }}>
      {TYPO_ROLES.map((role) => {
        const t = typography[role] || {}
        return (
          <fieldset key={role} style={{ border: '1px solid #E2E8F0', borderRadius: 12, padding: 16 }}>
            <legend style={{ fontWeight: 700, padding: '0 8px' }}>{role.replace(/_/g, ' ')}</legend>
            <div style={{ display: 'grid', gridTemplateColumns: 'repeat(auto-fill, minmax(140px, 1fr))', gap: 12 }}>
              {(['size_desktop', 'size_tablet', 'size_mobile', 'font_weight', 'line_height', 'letter_spacing', 'color'] as const).map((key) => (
                <label key={key} style={{ fontSize: 12 }}>
                  {key.replace(/_/g, ' ')}
                  <input
                    type="text"
                    value={t[key] || ''}
                    onChange={(e) => patch(['typography', role, key], e.target.value)}
                    style={{ display: 'block', width: '100%', marginTop: 4, padding: 6, borderRadius: 6, border: '1px solid #E2E8F0' }}
                  />
                </label>
              ))}
            </div>
          </fieldset>
        )
      })}
    </div>
  )
}

export function TokenGroupEditor({
  title,
  basePath,
  tokens,
  patch,
  keys,
}: {
  title: string
  basePath: string[]
  tokens: Record<string, string>
  patch: PatchFn
  keys: string[]
}) {
  return (
    <div>
      <h3 style={{ marginTop: 0 }}>{title}</h3>
      <div style={{ display: 'grid', gridTemplateColumns: 'repeat(auto-fill, minmax(220px, 1fr))', gap: 12 }}>
        {keys.map((key) => (
          <label key={key} style={{ fontSize: 13 }}>
            {key}
            <input
              type="text"
              value={tokens[key] || ''}
              onChange={(e) => patch([...basePath, key], e.target.value)}
              style={{ display: 'block', width: '100%', marginTop: 4, padding: 8, borderRadius: 8, border: '1px solid #E2E8F0' }}
            />
          </label>
        ))}
      </div>
    </div>
  )
}

export function ComponentsEditor({
  components,
  patch,
}: {
  components: Record<string, string>
  patch: PatchFn
}) {
  const keys = Object.keys(components).length ? Object.keys(components) : [
    'button_primary_bg', 'button_primary_color', 'button_radius',
    'card_radius', 'input_radius', 'input_border', 'card_shadow',
  ]
  return (
    <TokenGroupEditor
      title="Component tokens"
      basePath={['components']}
      tokens={components}
      patch={patch}
      keys={keys}
    />
  )
}

export function OverridesEditor({
  overrides,
  patch,
}: {
  overrides: Record<string, Record<string, unknown>>
  patch: PatchFn
}) {
  const pageTypes = (overrides.page_types || {}) as Record<string, unknown>
  const pages = (overrides.pages || {}) as Record<string, unknown>
  const sections = (overrides.sections || {}) as Record<string, unknown>

  const addKey = (bucket: 'page_types' | 'pages' | 'sections', key: string) => {
    if (!key.trim()) return
    patch(['overrides', bucket, key.trim()], { colors: { primary: '{colors.primary}' } })
  }

  return (
    <div style={{ display: 'grid', gap: 24 }}>
      <p style={{ color: '#64748B', margin: 0 }}>
        Override inheritance: Global → page type → page slug → section id. JSON patches merge shallowly per level.
      </p>
      {(
        [
          ['page_types', 'Page types (home, service, blog…)', pageTypes],
          ['pages', 'Page slugs', pages],
          ['sections', 'Section ids', sections],
        ] as const
      ).map(([bucket, label, map]) => (
        <div key={bucket} style={{ border: '1px solid #E2E8F0', borderRadius: 12, padding: 16 }}>
          <h3 style={{ marginTop: 0 }}>{label}</h3>
          {Object.keys(map).length === 0 && <p style={{ color: '#94A3B8', fontSize: 13 }}>No overrides yet.</p>}
          {Object.entries(map).map(([k, val]) => (
            <details key={k} style={{ marginBottom: 8 }}>
              <summary style={{ cursor: 'pointer', fontWeight: 600 }}>{k}</summary>
              <textarea
                defaultValue={JSON.stringify(val, null, 2)}
                rows={6}
                style={{ width: '100%', marginTop: 8, fontFamily: 'monospace', fontSize: 12 }}
                onBlur={(e) => {
                  try {
                    patch(['overrides', bucket, k], JSON.parse(e.target.value))
                  } catch {
                    /* keep previous */
                  }
                }}
              />
            </details>
          ))}
          <form
            onSubmit={(e) => {
              e.preventDefault()
              const fd = new FormData(e.currentTarget)
              addKey(bucket, String(fd.get('key') || ''))
              e.currentTarget.reset()
            }}
            style={{ display: 'flex', gap: 8, marginTop: 12 }}
          >
            <input name="key" placeholder="New key…" style={{ flex: 1, padding: 8, borderRadius: 8, border: '1px solid #E2E8F0' }} />
            <button type="submit" className="s2-btn s2-btn--outline">Add override</button>
          </form>
        </div>
      ))}
    </div>
  )
}

export type RegistryService = {
  id: number
  name: string
  slug: string
  public_status?: string
  direct_url_behavior?: string
  availability?: string
  visibility_rules?: Record<string, boolean | string>
  is_featured?: number
  is_popular?: number
}

export type RegistryCategory = {
  id: number
  name: string
  slug: string
  public_status?: string
  visibility_rules?: Record<string, boolean | string>
  hide_when_empty_children?: number
}

export function SurfaceVisibilityMatrix({
  surfaces,
  rules,
  onChange,
}: {
  surfaces: Record<string, string>
  rules: Record<string, boolean | string>
  onChange: (next: Record<string, boolean | string>) => void
}) {
  return (
    <div style={{ display: 'grid', gridTemplateColumns: 'repeat(auto-fill, minmax(240px, 1fr))', gap: 10 }}>
      {Object.entries(surfaces).map(([key, label]) => {
        const val = rules[key]
        const isDirect = key === 'direct_url'
        return (
          <label key={key} style={{ fontSize: 12, display: 'flex', flexDirection: 'column', gap: 4 }}>
            {label}
            {isDirect ? (
              <select
                value={typeof val === 'string' ? val : 'active'}
                onChange={(e) => onChange({ ...rules, [key]: e.target.value })}
                style={{ padding: 8, borderRadius: 8 }}
              >
                {['active', 'not_found', 'redirect_directory', 'redirect_home', 'unavailable_page'].map((b) => (
                  <option key={b} value={b}>{b.replace(/_/g, ' ')}</option>
                ))}
              </select>
            ) : (
              <select
                value={val === false ? 'off' : 'on'}
                onChange={(e) => onChange({ ...rules, [key]: e.target.value === 'on' })}
                style={{ padding: 8, borderRadius: 8 }}
              >
                <option value="on">Visible</option>
                <option value="off">Hidden on surface</option>
              </select>
            )}
          </label>
        )
      })}
    </div>
  )
}

export function CategoryRegistryPanel({
  categories,
  surfaces,
  onSave,
}: {
  categories: RegistryCategory[]
  surfaces: Record<string, string>
  onSave: (id: number, body: Record<string, unknown>) => Promise<void>
}) {
  const [selected, setSelected] = React.useState<number | null>(null)
  const cat = categories.find((c) => c.id === selected)

  return (
    <div style={{ display: 'grid', gridTemplateColumns: '1fr 1.2fr', gap: 20 }}>
      <div style={{ border: '1px solid #E2E8F0', borderRadius: 12, maxHeight: 420, overflow: 'auto' }}>
        {categories.map((c) => (
          <button
            key={c.id}
            type="button"
            onClick={() => setSelected(c.id)}
            style={{
              display: 'block',
              width: '100%',
              textAlign: 'left',
              padding: '12px 16px',
              border: 'none',
              borderBottom: '1px solid #F1F5F9',
              background: selected === c.id ? '#EBF0F8' : '#fff',
              cursor: 'pointer',
            }}
          >
            <strong>{c.name}</strong>
            <span style={{ float: 'right', fontSize: 12, color: '#64748B' }}>{c.public_status || 'published'}</span>
          </button>
        ))}
      </div>
      <div style={{ border: '1px solid #E2E8F0', borderRadius: 12, padding: 16 }}>
        {cat ? (
          <CategoryEditor cat={cat} surfaces={surfaces} onSave={onSave} />
        ) : (
          <p style={{ color: '#94A3B8' }}>Select a category to edit visibility.</p>
        )}
      </div>
    </div>
  )
}

function CategoryEditor({
  cat,
  surfaces,
  onSave,
}: {
  cat: RegistryCategory
  surfaces: Record<string, string>
  onSave: (id: number, body: Record<string, unknown>) => Promise<void>
}) {
  const [status, setStatus] = React.useState(cat.public_status || 'published')
  const [rules, setRules] = React.useState<Record<string, boolean | string>>(cat.visibility_rules || {})
  const [hideEmpty, setHideEmpty] = React.useState(Boolean(cat.hide_when_empty_children ?? 1))

  React.useEffect(() => {
    setStatus(cat.public_status || 'published')
    setRules(cat.visibility_rules || {})
    setHideEmpty(Boolean(cat.hide_when_empty_children ?? 1))
  }, [cat.id, cat.public_status, cat.visibility_rules, cat.hide_when_empty_children])

  return (
    <div>
      <h3 style={{ marginTop: 0 }}>{cat.name}</h3>
      <label style={{ fontSize: 13, fontWeight: 600 }}>Publication status</label>
      <select value={status} onChange={(e) => setStatus(e.target.value)} style={{ width: '100%', padding: 10, borderRadius: 8, marginBottom: 12 }}>
        {['published', 'hidden', 'draft', 'disabled', 'coming_soon'].map((st) => (
          <option key={st} value={st}>{st}</option>
        ))}
      </select>
      <label style={{ display: 'flex', alignItems: 'center', gap: 8, fontSize: 13, marginBottom: 16 }}>
        <input type="checkbox" checked={hideEmpty} onChange={(e) => setHideEmpty(e.target.checked)} />
        Hide category when no visible child services
      </label>
      <SurfaceVisibilityMatrix surfaces={surfaces} rules={rules} onChange={setRules} />
      <button
        type="button"
        className="s2-btn s2-btn--primary"
        style={{ marginTop: 16 }}
        onClick={() => onSave(cat.id, { public_status: status, visibility_rules: rules, hide_when_empty_children: hideEmpty ? 1 : 0 })}
      >
        Save category visibility
      </button>
    </div>
  )
}
