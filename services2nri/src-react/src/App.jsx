import { useState, useMemo } from 'react'
import './styles/global.css'
import FormBuilderPage from './pages/FormBuilder'
import HomepageBuilderPage from './pages/HomepageBuilder'
import ServiceBuilderPage from './pages/ServiceBuilder'
import StatusManagerPage from './pages/StatusManager'
import BuilderBottomNav from './components/BuilderBottomNav'
import { BuilderStickyProvider } from './components/BuilderMobileUi'

/* ── Navigation config ───────────────────────────────────────────────── */
const NAV = [
  {
    section: 'Content Builders',
    items: [
      { id: 'homepage',  label: 'Homepage Builder',    icon: '🏠', desc: 'Hero, marquee, sections, SEO' },
      { id: 'service',   label: 'Service Page Builder', icon: '📄', desc: 'Per-service hero, sections, nav' },
      { id: 'forms',     label: 'Form Builder',         icon: '📝', desc: 'Fields, logic, multi-step forms' },
    ],
  },
  {
    section: 'Workflow',
    items: [
      { id: 'status',    label: 'Status Manager',       icon: '⚡', desc: 'Booking workflow and statuses' },
    ],
  },
]

/* ── Root App ────────────────────────────────────────────────────────── */
export default function App() {
  const initialPage = (() => {
    const params = new URLSearchParams(window.location.search)
    const requested = params.get('page')
    const pageIdMap = { 'form-builder': 'forms', 'homepage-builder': 'homepage', 'service-builder': 'service', 'status-manager': 'status' }
    const mapped = requested ? (pageIdMap[requested] || requested) : null
    const validIds = NAV.flatMap(g => g.items).map(i => i.id)
    return mapped && validIds.includes(mapped) ? mapped : 'homepage'
  })()
  const [page, setPage] = useState(initialPage)
  const [mobileOpen, setMobileOpen] = useState(false)

  const config = window.S2NRI_BUILDER || {}
  const backUrl = config.adminUrl || '/admin'
  const siteUrl = config.siteUrl || '/'

  const fallbackSticky = useMemo(() => (
    <>
      <a href={siteUrl} target="_blank" rel="noreferrer" className="btn btn-primary btn-lg s2-builder-sticky-primary">
        View Site ↗
      </a>
      <a href={backUrl} className="btn btn-secondary btn-lg s2-builder-sticky-secondary">
        Admin Dashboard
      </a>
    </>
  ), [siteUrl, backUrl])

  return (
    <div className="s2builder-app s2-mobile-app-shell">
      {/* Sidebar */}
      <aside className={`s2builder-sidebar ${mobileOpen ? 'open' : ''}`}>
        <div className="sidebar-logo">
          <h1>🏗️ S2NRI Builder</h1>
          <span>v{config.version || '4.3.26'}</span>
        </div>

        {NAV.map(group => (
          <div key={group.section}>
            <div className="sidebar-section-label">{group.section}</div>
            {group.items.map(item => (
              <button
                key={item.id}
                className={`sidebar-link ${page === item.id ? 'active' : ''}`}
                onClick={() => { setPage(item.id); setMobileOpen(false) }}
                title={item.desc}
              >
                <span className="icon">{item.icon}</span>
                {item.label}
              </button>
            ))}
          </div>
        ))}

        <div style={{ marginTop: 'auto', padding: 16, borderTop: '1px solid rgba(255,255,255,.08)' }}>
          <a
            href={backUrl}
            className="sidebar-link"
            style={{ textDecoration: 'none' }}
          >
            <span className="icon">←</span>
            Back to Admin
          </a>
        </div>
      </aside>

      {mobileOpen && (
        <button
          type="button"
          className="s2builder-sidebar-backdrop"
          aria-label="Close menu"
          onClick={() => setMobileOpen(false)}
        />
      )}

      {/* Main content */}
      <div className="s2builder-main">
        <header className="s2builder-topbar">
          <button
            type="button"
            onClick={() => setMobileOpen(o => !o)}
            className="mobile-menu-btn"
            aria-label="Open builder menu"
          >
            ☰
          </button>

          <div className="s2builder-topbar-breadcrumb">
            <span>Builder</span>
            <span>›</span>
            <span className="s2builder-topbar-breadcrumb__current">
              {NAV.flatMap(g => g.items).find(i => i.id === page)?.label}
            </span>
          </div>

          <div className="s2builder-topbar-actions s2-builder-topbar-actions--desktop-only">
            <a
              href={siteUrl}
              target="_blank"
              rel="noreferrer"
              className="s2builder-view-site-link"
            >
              View Site ↗
            </a>
          </div>
        </header>

        <BuilderStickyProvider fallbackSticky={fallbackSticky}>
          <main className="s2builder-content s2-mobile-app-surface">
            {page === 'homepage' && <HomepageBuilderPage />}
            {page === 'service'  && <ServiceBuilderPage />}
            {page === 'forms'    && <FormBuilderPage />}
            {page === 'status'   && <StatusManagerPage />}
          </main>
        </BuilderStickyProvider>

        <BuilderBottomNav page={page} setPage={setPage} onMenu={() => setMobileOpen(true)} />
      </div>
    </div>
  )
}
