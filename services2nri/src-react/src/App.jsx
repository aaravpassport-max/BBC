import { useState } from 'react'
import './styles/global.css'
import FormBuilderPage from './pages/FormBuilder'
import HomepageBuilderPage from './pages/HomepageBuilder'
import ServiceBuilderPage from './pages/ServiceBuilder'
import StatusManagerPage from './pages/StatusManager'

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
  // FIXED: this state previously always started at the hardcoded
  // 'homepage' value, completely ignoring whatever ?page=X was in the URL
  // that got the admin here. AdminServices (the main admin app) links into
  // this Builder app with URLs like
  // "?page=form-builder&service=5" in 3 live places — the post-create
  // redirect after making a new service, the field-count badge, and the
  // "Form" button in the services table. Every one of those landed on the
  // Homepage Builder instead, since nothing here ever read the URL.
  // FormBuilderPage itself already correctly reads ?service= from the URL
  // (confirmed, unrelated bug) — only this page-level routing was broken.
  // Maps the external "form-builder" URL value to this app's internal
  // 'forms' nav id.
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

  return (
    <div className="s2builder-app">
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

      {/* Main content */}
      <div className="s2builder-main">
        {/* Top bar */}
        <header className="s2builder-topbar">
          <button
            onClick={() => setMobileOpen(o => !o)}
            style={{ display: 'none', border: 'none', background: 'none', cursor: 'pointer', fontSize: 18, padding: 4 }}
            className="mobile-menu-btn"
          >
            ☰
          </button>

          {/* Breadcrumb */}
          <div style={{ fontSize: 13, color: 'var(--text-muted)', display: 'flex', alignItems: 'center', gap: 6 }}>
            <span>Builder</span>
            <span>›</span>
            <span style={{ color: 'var(--text)', fontWeight: 600 }}>
              {NAV.flatMap(g => g.items).find(i => i.id === page)?.label}
            </span>
          </div>

          <div style={{ marginLeft: 'auto', display: 'flex', gap: 8, alignItems: 'center' }}>
            <a
              href={config.siteUrl || '/'}
              target="_blank"
              rel="noreferrer"
              style={{ fontSize: 12, color: 'var(--text-muted)', textDecoration: 'none', display: 'flex', alignItems: 'center', gap: 4 }}
            >
              View Site ↗
            </a>
          </div>
        </header>

        {/* Page content */}
        <main className="s2builder-content">
          {page === 'homepage' && <HomepageBuilderPage />}
          {page === 'service'  && <ServiceBuilderPage />}
          {page === 'forms'    && <FormBuilderPage />}
          {page === 'status'   && <StatusManagerPage />}
        </main>
      </div>

      <style>{`
        @media (max-width: 768px) {
          .mobile-menu-btn { display: flex !important; }
        }
      `}</style>
    </div>
  )
}
