const NAV_ITEMS = [
  { id: 'homepage', label: 'Home', icon: '🏠' },
  { id: 'service', label: 'Service', icon: '📄' },
  { id: 'forms', label: 'Forms', icon: '📝' },
  { id: 'status', label: 'Status', icon: '⚡' },
]

export default function BuilderBottomNav({ page, setPage, onMenu }) {
  return (
    <nav className="s2-builder-bottom-nav" aria-label="Builder mobile navigation">
      {NAV_ITEMS.map((item) => (
        <button
          key={item.id}
          type="button"
          className={`s2-builder-bottom-nav-item${page === item.id ? ' active' : ''}`}
          onClick={() => setPage(item.id)}
        >
          <span className="s2-builder-bottom-nav-icon" aria-hidden>{item.icon}</span>
          <span className="s2-builder-bottom-nav-label">{item.label}</span>
        </button>
      ))}
      <button type="button" className="s2-builder-bottom-nav-item" onClick={onMenu}>
        <span className="s2-builder-bottom-nav-icon" aria-hidden>☰</span>
        <span className="s2-builder-bottom-nav-label">Menu</span>
      </button>
    </nav>
  )
}
