import React, { createContext, useContext, useMemo } from 'react'

const BuilderStickyContext = createContext(null)

/** Dashboard-style screen wrapper with optional bottom sticky actions (mobile). */
export function BuilderScreen({ children, sticky, className = '' }) {
  return (
    <div className={`s2-builder-screen${sticky ? ' s2-builder-screen--sticky' : ''} ${className}`.trim()}>
      {children}
      {sticky ? (
        <div className="s2-builder-sticky-action-bar" role="region" aria-label="Page actions">
          <div className="s2-builder-sticky-action-bar__inner">{sticky}</div>
        </div>
      ) : null}
    </div>
  )
}

export function BuilderToolbar({ children, className = '' }) {
  return <div className={`s2-builder-toolbar ${className}`.trim()}>{children}</div>
}

export function BuilderStickyProvider({ children, fallbackSticky = null }) {
  const [sticky, setSticky] = React.useState(null)
  const ctx = useMemo(() => ({ setSticky }), [])
  const effective = sticky ?? fallbackSticky

  return (
    <BuilderStickyContext.Provider value={ctx}>
      <BuilderScreen sticky={effective}>{children}</BuilderScreen>
    </BuilderStickyContext.Provider>
  )
}

/** Register page-level sticky actions (cleared on unmount). */
export function useBuilderSticky(stickyNode) {
  const ctx = useContext(BuilderStickyContext)
  React.useEffect(() => {
    if (!ctx) return undefined
    ctx.setSticky(stickyNode)
    return () => ctx.setSticky(null)
  }, [ctx, stickyNode])
}
