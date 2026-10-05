/**
 * Premium homepage section list — reorder, visibility, quick edit entry.
 */
import React from 'react'
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

function isVisible(settings: Record<string, string>, section: SectionCatalogDef): boolean {
  if (!section.hideSettingKey) return true
  return readAdminSetting(settings, section.hideSettingKey) !== '1'
}

function SortableSectionCard({
  section,
  settings,
  isActive,
  isHidden,
  onToggleVisibility,
  onOpenTab,
  onSelect,
}: {
  section: SectionCatalogDef
  settings: Record<string, string>
  isActive: boolean
  isHidden: boolean
  onToggleVisibility: () => void
  onOpenTab: (tab: SectionEditorTab) => void
  onSelect: () => void
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
        <button type="button" className="s2-ds-quick-tab" onClick={() => onOpenTab('content')}>
          Content
        </button>
        <button type="button" className="s2-ds-quick-tab s2-ds-quick-tab--design" onClick={() => onOpenTab('design')}>
          Design
        </button>
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

  return (
    <div className="s2-ds-section-manager">
      <div className="s2-ds-section-manager__toolbar">
        <div>
          <h3 className="s2-ds-section-manager__title">{page.label} sections</h3>
          <p className="s2-ds-section-manager__lead">
            Drag to reorder{reorderEnabled ? '' : ' (order applies on Homepage)'}. Toggle visibility without losing content.
          </p>
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
              {orderedSections.map((sec) => (
                <SortableSectionCard
                  key={sec.id}
                  section={sec}
                  settings={settings}
                  isActive={activeSectionId === sec.id}
                  isHidden={!isVisible(settings, sec)}
                  onToggleVisibility={() => onVisibilityChange(sec, !isVisible(settings, sec))}
                  onOpenTab={(tab) => onOpenSectionTab(sec.id, tab)}
                  onSelect={() => onSelectSection(sec.id)}
                />
              ))}
            </div>
          </SortableContext>
        </DndContext>
      ) : (
        <div className="s2-ds-section-manager__list">
          {sections.map((sec) => (
            <div
              key={sec.id}
              className={`s2-ds-section-card s2-ds-section-card--static${activeSectionId === sec.id ? ' is-active' : ''}${!isVisible(settings, sec) ? ' is-hidden' : ''}`}
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
              </div>
            </div>
          ))}
        </div>
      )}
    </div>
  )
}
