/**
 * Premium section list — reorder, visibility, quick edit entry.
 */
import React, { useState } from 'react'
import {
  DndContext,
  closestCenter,
  KeyboardSensor,
  PointerSensor,
  useSensor,
  useSensors,
  type DragEndEvent,
} from '@dnd-kit/core'
import {
  SortableContext,
  sortableKeyboardCoordinates,
  useSortable,
  verticalListSortingStrategy,
  arrayMove,
} from '@dnd-kit/sortable'
import { CSS } from '@dnd-kit/utilities'
import type { PageCatalogDef, SectionCatalogDef } from '@/lib/design-system-catalog'
import { readAdminSetting } from '@/lib/settings-admin'

export const SECTION_ICONS: Record<string, string> = {
  _page: '◆',
  hero: '⌂',
  notice: '!',
  search: '⌕',
  features: '★',
  services: '▦',
  cities: '📍',
  stats: '#',
  tagline: '—',
  testimonials: '“',
  how: '→',
  press: '📰',
  partners: '🤝',
  about: 'ℹ',
  awards: '🏆',
  faq: '?',
  newsletter: '✉',
  app: '📱',
  locations: '🌐',
  footer: '▔',
  intro: '▣',
  feed: '☰',
  content: '¶',
}

type SectionEditorTab = 'content' | 'design'

function SectionRow({
  sectionId,
  isActive,
  children,
}: {
  sectionId: string
  isActive: boolean
  children: React.ReactNode
}) {
  const rowRef = React.useRef<HTMLDivElement>(null)
  React.useEffect(() => {
    if (!isActive || !rowRef.current) return
    rowRef.current.scrollIntoView({ behavior: 'smooth', block: 'nearest' })
  }, [isActive, sectionId])
  return (
    <div ref={rowRef} className={`s2-ds-section-row${isActive ? ' is-editing' : ''}`}>
      {children}
    </div>
  )
}

function isVisible(settings: Record<string, string>, section: SectionCatalogDef): boolean {
  if (!section.hideSettingKey) return true
  return readAdminSetting(settings, section.hideSettingKey) !== '1'
}

function SectionMoreMenu({
  section,
  onReset,
  resetting,
}: {
  section: SectionCatalogDef
  onReset?: (section: SectionCatalogDef) => void
  resetting: boolean
}) {
  const [open, setOpen] = useState(false)
  if (section.isPageScope || !onReset) return null
  return (
    <div className="s2-ds-more-menu">
      <button
        type="button"
        className="s2-ds-icon-btn"
        aria-expanded={open}
        aria-haspopup="menu"
        title="More actions"
        onClick={(e) => {
          e.stopPropagation()
          setOpen((v) => !v)
        }}
      >
        ⋮
      </button>
      {open && (
        <>
          <button type="button" className="s2-ds-more-menu__backdrop" aria-label="Close menu" onClick={() => setOpen(false)} />
          <div className="s2-ds-more-menu__panel" role="menu">
            <button
              type="button"
              role="menuitem"
              className="s2-ds-more-menu__item"
              disabled={resetting}
              onClick={() => {
                setOpen(false)
                onReset(section)
              }}
            >
              ↺ Reset section
            </button>
            <p className="s2-ds-more-menu__hint">Clears copy, styling overrides, and shows the section again.</p>
          </div>
        </>
      )}
    </div>
  )
}

function SortableSectionCard({
  section,
  isActive,
  isHidden,
  onToggleVisibility,
  onOpenTab,
  onSelect,
  onReset,
  resetting,
}: {
  section: SectionCatalogDef
  isActive: boolean
  isHidden: boolean
  onToggleVisibility: () => void
  onOpenTab: (tab: SectionEditorTab) => void
  onSelect: () => void
  onReset?: (section: SectionCatalogDef) => void
  resetting: boolean
}) {
  const { attributes, listeners, setNodeRef, transform, transition, isDragging } = useSortable({
    id: section.id,
  })
  const style: React.CSSProperties = {
    transform: CSS.Transform.toString(transform),
    transition,
    opacity: isDragging ? 0.92 : 1,
  }
  const icon = SECTION_ICONS[section.id] || SECTION_ICONS[section.sectionKey] || '☰'

  return (
    <div
      ref={setNodeRef}
      style={style}
      className={`s2-ds-section-card${isActive ? ' is-active' : ''}${isHidden ? ' is-hidden' : ''}${isDragging ? ' is-dragging' : ''}`}
    >
      <button type="button" className="s2-ds-section-card__drag" aria-label={`Reorder ${section.label}`} {...attributes} {...listeners}>
        ☰
      </button>
      <button type="button" className="s2-ds-section-card__main" onClick={onSelect}>
        <span className="s2-ds-section-card__icon" aria-hidden>
          {icon}
        </span>
        <span className="s2-ds-section-card__text">
          <span className="s2-ds-section-card__title">{section.label}</span>
          {isHidden && <span className="s2-ds-section-card__badge">Hidden on site</span>}
        </span>
      </button>
      <div className="s2-ds-section-card__actions">
        {section.hideSettingKey && (
          <button
            type="button"
            className={`s2-ds-icon-btn${!isHidden ? ' is-on' : ''}`}
            title={isHidden ? 'Show section on public page' : 'Hide section on public page'}
            aria-pressed={!isHidden}
            onClick={(e) => {
              e.stopPropagation()
              onToggleVisibility()
            }}
          >
            👁
          </button>
        )}
        <button type="button" className="s2-ds-quick-tab" onClick={() => onOpenTab('content')}>
          Content
        </button>
        <button type="button" className="s2-ds-quick-tab s2-ds-quick-tab--design" onClick={() => onOpenTab('design')}>
          Design
        </button>
        <SectionMoreMenu section={section} onReset={onReset} resetting={resetting} />
      </div>
    </div>
  )
}

export function DesignSectionManager({
  page,
  sections,
  orderedIds,
  onReorder,
  settings,
  onVisibilityChange,
  activeSectionId,
  onSelectSection,
  onOpenSectionTab,
  onSaveStructure,
  structureSaving,
  structureDirty,
  structureMessage,
  reorderEnabled,
  onResetSection,
  resettingSectionId,
  orderAppliesOnLiveSite,
  renderInlineEditor,
}: {
  page: PageCatalogDef
  sections: SectionCatalogDef[]
  orderedIds: string[]
  onReorder: (ids: string[]) => void
  settings: Record<string, string>
  onVisibilityChange: (section: SectionCatalogDef, visible: boolean) => void
  activeSectionId: string | null
  onSelectSection: (id: string) => void
  onOpenSectionTab: (id: string, tab: SectionEditorTab) => void
  onSaveStructure: () => void
  structureSaving: boolean
  structureDirty: boolean
  structureMessage: { type: 'success' | 'error'; text: string } | null
  reorderEnabled: boolean
  onResetSection?: (section: SectionCatalogDef) => void
  resettingSectionId?: string | null
  orderAppliesOnLiveSite?: boolean
  renderInlineEditor?: (section: SectionCatalogDef) => React.ReactNode
}) {
  const sensors = useSensors(
    useSensor(PointerSensor, { activationConstraint: { distance: 6 } }),
    useSensor(KeyboardSensor, { coordinateGetter: sortableKeyboardCoordinates }),
  )

  const sectionById = React.useMemo(() => {
    const m = new Map<string, SectionCatalogDef>()
    sections.forEach((s) => m.set(s.id, s))
    return m
  }, [sections])

  const orderedSections = orderedIds.map((id) => sectionById.get(id)).filter(Boolean) as SectionCatalogDef[]

  const onDragEnd = (event: DragEndEvent) => {
    const { active, over } = event
    if (!over || active.id === over.id) return
    const oldIndex = orderedIds.indexOf(String(active.id))
    const newIndex = orderedIds.indexOf(String(over.id))
    if (oldIndex < 0 || newIndex < 0) return
    onReorder(arrayMove(orderedIds, oldIndex, newIndex))
  }

  const lead = orderAppliesOnLiveSite
    ? 'Drag to reorder homepage bands. Toggle visibility without losing content.'
    : 'Drag to set your preferred section order (saved for this page). Visibility applies on the live site where supported.'

  return (
    <div className="s2-ds-section-manager">
      <div className="s2-ds-section-manager__toolbar">
        <div>
          <h3 className="s2-ds-section-manager__title">{page.label} sections</h3>
          <p className="s2-ds-section-manager__lead">{lead}</p>
        </div>
        <div className="s2-ds-section-manager__toolbar-actions">
          {structureDirty && <span className="s2-ds-status-chip s2-ds-status-chip--warn">Unsaved changes</span>}
          <button
            type="button"
            className="s2-btn s2-btn--primary s2-btn--sm"
            disabled={!structureDirty || structureSaving}
            onClick={onSaveStructure}
          >
            {structureSaving ? 'Saving…' : 'Save page structure'}
          </button>
        </div>
      </div>
      {structureMessage && (
        <p className={`s2-design-builder-toast s2-design-builder-toast--${structureMessage.type}`} role="status">
          {structureMessage.text}
        </p>
      )}

      {reorderEnabled ? (
        <DndContext sensors={sensors} collisionDetection={closestCenter} onDragEnd={onDragEnd}>
          <SortableContext items={orderedIds} strategy={verticalListSortingStrategy}>
            <div className="s2-ds-section-manager__list">
              {orderedSections.map((sec) => {
                const isActive = activeSectionId === sec.id
                return (
                  <SectionRow key={sec.id} sectionId={sec.id} isActive={isActive}>
                    <SortableSectionCard
                      section={sec}
                      isActive={isActive}
                      isHidden={!isVisible(settings, sec)}
                      onToggleVisibility={() => onVisibilityChange(sec, !isVisible(settings, sec))}
                      onOpenTab={(tab) => onOpenSectionTab(sec.id, tab)}
                      onSelect={() => onSelectSection(sec.id)}
                      onReset={onResetSection}
                      resetting={resettingSectionId === sec.id}
                    />
                    {isActive && renderInlineEditor?.(sec)}
                  </SectionRow>
                )
              })}
            </div>
          </SortableContext>
        </DndContext>
      ) : (
        <div className="s2-ds-section-manager__list">
          {sections.map((sec) => {
            const isActive = activeSectionId === sec.id
            return (
              <SectionRow key={sec.id} sectionId={sec.id} isActive={isActive}>
                <div
                  className={`s2-ds-section-card s2-ds-section-card--static${isActive ? ' is-active' : ''}${!isVisible(settings, sec) ? ' is-hidden' : ''}`}
                >
                  <span className="s2-ds-section-card__icon" aria-hidden>
                    {SECTION_ICONS[sec.id] || '☰'}
                  </span>
                  <button type="button" className="s2-ds-section-card__main" onClick={() => onSelectSection(sec.id)}>
                    <span className="s2-ds-section-card__title">{sec.label}</span>
                    {!isVisible(settings, sec) && <span className="s2-ds-section-card__badge">Hidden</span>}
                  </button>
                  <div className="s2-ds-section-card__actions">
                    {sec.hideSettingKey && (
                      <button
                        type="button"
                        className={`s2-ds-icon-btn${isVisible(settings, sec) ? ' is-on' : ''}`}
                        onClick={() => onVisibilityChange(sec, !isVisible(settings, sec))}
                      >
                        👁
                      </button>
                    )}
                    <button type="button" className="s2-ds-quick-tab" onClick={() => onOpenSectionTab(sec.id, 'content')}>
                      Content
                    </button>
                    <button type="button" className="s2-ds-quick-tab s2-ds-quick-tab--design" onClick={() => onOpenSectionTab(sec.id, 'design')}>
                      Design
                    </button>
                    <SectionMoreMenu
                      section={sec}
                      onReset={onResetSection}
                      resetting={resettingSectionId === sec.id}
                    />
                  </div>
                </div>
                {isActive && renderInlineEditor?.(sec)}
              </SectionRow>
            )
          })}
        </div>
      )}
    </div>
  )
}
