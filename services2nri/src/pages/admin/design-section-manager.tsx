/**
 * Premium section list — reference-style band cards, inline expand, drag reorder.
 */
import React, { useEffect, useRef } from 'react'
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
import { SECTION_BAND_COPY, sectionHasCustomDesign } from './design-visual-section-ui'

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

export type SectionEditorTab = 'content' | 'design' | 'elements'

function isVisible(settings: Record<string, string>, section: SectionCatalogDef): boolean {
  if (!section.hideSettingKey) return true
  return readAdminSetting(settings, section.hideSettingKey) !== '1'
}

function sectionSubtitle(section: SectionCatalogDef): string {
  return SECTION_BAND_COPY[section.id]?.subtitle || SECTION_BAND_COPY[section.sectionKey]?.subtitle || ''
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
  const [open, setOpen] = React.useState(false)
  if (section.isPageScope || !onReset) return null
  return (
    <div className="s2-ds-more-menu">
      <button
        type="button"
        className="s2-ds-icon-btn s2-ds-icon-btn--ghost"
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

function BandCardHeader({
  section,
  index,
  isActive,
  isHidden,
  customDesign,
  dragHandle,
  onToggle,
  onToggleVisibility,
  onReset,
  resetting,
}: {
  section: SectionCatalogDef
  index: number
  isActive: boolean
  isHidden: boolean
  customDesign: boolean
  dragHandle?: React.ReactNode
  onToggle: () => void
  onToggleVisibility: () => void
  onReset?: (section: SectionCatalogDef) => void
  resetting: boolean
}) {
  const subtitle = sectionSubtitle(section)
  const icon = SECTION_ICONS[section.id] || SECTION_ICONS[section.sectionKey] || '☰'

  return (
    <div className={`s2-band-card${isActive ? ' is-active' : ''}${isHidden ? ' is-hidden' : ''}`}>
      <div className="s2-band-card__lead">
        {dragHandle ?? <span className="s2-band-card__index" aria-hidden>{index + 1}</span>}
        <span className="s2-band-card__icon" aria-hidden>
          {icon}
        </span>
      </div>
      <button type="button" className="s2-band-card__main" onClick={onToggle} aria-expanded={isActive}>
        <span className="s2-band-card__titles">
          <span className="s2-band-card__title">{section.label}</span>
          {subtitle && <span className="s2-band-card__subtitle">{subtitle}</span>}
        </span>
        <span className="s2-band-card__meta">
          {customDesign && <span className="s2-band-card__pill">Custom design</span>}
          {isHidden && <span className="s2-band-card__pill s2-band-card__pill--muted">Hidden</span>}
        </span>
      </button>
      <div className="s2-band-card__actions">
        {section.hideSettingKey && (
          <button
            type="button"
            className={`s2-ds-icon-btn s2-ds-icon-btn--ghost${!isHidden ? ' is-on' : ''}`}
            title={isHidden ? 'Show on public page' : 'Hide on public page'}
            aria-pressed={!isHidden}
            onClick={(e) => {
              e.stopPropagation()
              onToggleVisibility()
            }}
          >
            <span aria-hidden>👁</span>
          </button>
        )}
        <SectionMoreMenu section={section} onReset={onReset} resetting={resetting} />
        <button type="button" className="s2-band-card__chevron-btn" onClick={onToggle} aria-label={isActive ? 'Collapse section' : 'Expand section'}>
          <span className={`s2-band-card__chevron${isActive ? ' is-open' : ''}`} aria-hidden />
        </button>
      </div>
    </div>
  )
}

function SectionBandItem({
  section,
  index,
  settings,
  config,
  isActive,
  isHidden,
  onToggleVisibility,
  onSelect,
  onReset,
  resetting,
  inlineEditor,
  dragHandle,
  itemRef,
  style,
  isDragging,
}: {
  section: SectionCatalogDef
  index: number
  settings: Record<string, string>
  config: Record<string, unknown>
  isActive: boolean
  isHidden: boolean
  onToggleVisibility: () => void
  onSelect: () => void
  onReset?: (section: SectionCatalogDef) => void
  resetting: boolean
  inlineEditor: React.ReactNode | null
  dragHandle?: React.ReactNode
  itemRef: (node: HTMLDivElement | null) => void
  style?: React.CSSProperties
  isDragging?: boolean
}) {
  const customDesign = sectionHasCustomDesign(section, settings, config)

  return (
    <div
      ref={itemRef}
      style={style}
      className={`s2-band-item${isActive ? ' is-editing' : ''}${isHidden ? ' is-section-hidden' : ''}${isDragging ? ' is-dragging' : ''}`}
      data-section-id={section.id}
    >
      <BandCardHeader
        section={section}
        index={index}
        isActive={isActive}
        isHidden={isHidden}
        customDesign={customDesign}
        dragHandle={dragHandle}
        onToggle={onSelect}
        onToggleVisibility={onToggleVisibility}
        onReset={onReset}
        resetting={resetting}
      />
      {isActive && inlineEditor && (
        <div className="s2-band-item__editor" data-testid={`section-editor-${section.id}`}>
          {inlineEditor}
        </div>
      )}
    </div>
  )
}

function SortableSectionItem({
  section,
  index,
  settings,
  config,
  isActive,
  isHidden,
  onToggleVisibility,
  onSelect,
  onReset,
  resetting,
  inlineEditor,
}: {
  section: SectionCatalogDef
  index: number
  settings: Record<string, string>
  config: Record<string, unknown>
  isActive: boolean
  isHidden: boolean
  onToggleVisibility: () => void
  onSelect: () => void
  onReset?: (section: SectionCatalogDef) => void
  resetting: boolean
  inlineEditor: React.ReactNode | null
}) {
  const itemRef = useRef<HTMLDivElement>(null)
  const { attributes, listeners, setNodeRef, transform, transition, isDragging } = useSortable({
    id: section.id,
  })

  const setRefs = (node: HTMLDivElement | null) => {
    setNodeRef(node)
    ;(itemRef as React.MutableRefObject<HTMLDivElement | null>).current = node
  }

  useEffect(() => {
    if (!isActive || !itemRef.current) return
    itemRef.current.scrollIntoView({ behavior: 'smooth', block: 'start' })
  }, [isActive, section.id])

  const style: React.CSSProperties = {
    transform: CSS.Transform.toString(transform),
    transition,
  }

  const dragHandle = (
    <button
      type="button"
      className="s2-band-card__drag"
      aria-label={`Reorder ${section.label}`}
      {...attributes}
      {...listeners}
    >
      ⠿
    </button>
  )

  return (
    <SectionBandItem
      section={section}
      index={index}
      settings={settings}
      config={config}
      isActive={isActive}
      isHidden={isHidden}
      onToggleVisibility={onToggleVisibility}
      onSelect={onSelect}
      onReset={onReset}
      resetting={resetting}
      inlineEditor={inlineEditor}
      dragHandle={dragHandle}
      itemRef={setRefs}
      style={style}
      isDragging={isDragging}
    />
  )
}

function StaticSectionItem({
  section,
  index,
  settings,
  config,
  isActive,
  isHidden,
  onToggleVisibility,
  onSelectSection,
  onResetSection,
  resettingSectionId,
  inlineEditor,
}: {
  section: SectionCatalogDef
  index: number
  settings: Record<string, string>
  config: Record<string, unknown>
  isActive: boolean
  isHidden: boolean
  onToggleVisibility: () => void
  onSelectSection: (id: string) => void
  onResetSection?: (section: SectionCatalogDef) => void
  resettingSectionId?: string | null
  inlineEditor: React.ReactNode | null
}) {
  const itemRef = useRef<HTMLDivElement | null>(null)
  const setItemRef = (node: HTMLDivElement | null) => {
    itemRef.current = node
  }
  useEffect(() => {
    if (!isActive || !itemRef.current) return
    itemRef.current.scrollIntoView({ behavior: 'smooth', block: 'start' })
  }, [isActive, section.id])

  return (
    <SectionBandItem
      section={section}
      index={index}
      settings={settings}
      config={config}
      isActive={isActive}
      isHidden={isHidden}
      onToggleVisibility={onToggleVisibility}
      onSelect={() => onSelectSection(section.id)}
      onReset={onResetSection}
      resetting={resettingSectionId === section.id}
      inlineEditor={inlineEditor}
      itemRef={setItemRef}
    />
  )
}

export function DesignSectionManager({
  page,
  sections,
  orderedIds,
  onReorder,
  settings,
  config,
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
  config: Record<string, unknown>
  onVisibilityChange: (section: SectionCatalogDef, visible: boolean) => void
  activeSectionId: string | null
  onSelectSection: (id: string) => void
  /** @deprecated Header quick-tabs removed; kept for API compatibility */
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
  void onOpenSectionTab

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
    ? 'Expand a band to edit Content or Design in place. Drag the grip to reorder; save structure when finished.'
    : 'Expand a band to edit in place. Drag to reorder sections, then save structure.'

  return (
    <div className="s2-ds-section-manager s2-band-section-manager">
      <div className="s2-ds-section-manager__toolbar">
        <div>
          <h3 className="s2-ds-section-manager__title">{page.label} sections</h3>
          <p className="s2-ds-section-manager__lead">{lead}</p>
        </div>
        <div className="s2-ds-section-manager__toolbar-actions">
          {structureDirty && <span className="s2-ds-status-chip s2-ds-status-chip--warn">Unsaved order</span>}
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
            <div className="s2-band-section-list">
              {orderedSections.map((sec, idx) => {
                const isActive = activeSectionId === sec.id
                return (
                  <SortableSectionItem
                    key={sec.id}
                    section={sec}
                    index={idx}
                    settings={settings}
                    config={config}
                    isActive={isActive}
                    isHidden={!isVisible(settings, sec)}
                    onToggleVisibility={() => onVisibilityChange(sec, !isVisible(settings, sec))}
                    onSelect={() => onSelectSection(sec.id)}
                    onReset={onResetSection}
                    resetting={resettingSectionId === sec.id}
                    inlineEditor={isActive && renderInlineEditor ? renderInlineEditor(sec) : null}
                  />
                )
              })}
            </div>
          </SortableContext>
        </DndContext>
      ) : (
        <div className="s2-band-section-list">
          {sections.map((sec, idx) => {
            const isActive = activeSectionId === sec.id
            return (
              <StaticSectionItem
                key={sec.id}
                section={sec}
                index={idx}
                settings={settings}
                config={config}
                isActive={isActive}
                isHidden={!isVisible(settings, sec)}
                onToggleVisibility={() => onVisibilityChange(sec, !isVisible(settings, sec))}
                onSelectSection={onSelectSection}
                onResetSection={onResetSection}
                resettingSectionId={resettingSectionId}
                inlineEditor={isActive && renderInlineEditor ? renderInlineEditor(sec) : null}
              />
            )
          })}
        </div>
      )}
    </div>
  )
}
