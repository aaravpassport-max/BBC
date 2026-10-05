import { useState, useEffect, useMemo } from 'react'
import {
  DndContext, closestCenter, PointerSensor, KeyboardSensor,
  useSensor, useSensors,
} from '@dnd-kit/core'
import {
  arrayMove, SortableContext, sortableKeyboardCoordinates,
  useSortable, verticalListSortingStrategy,
} from '@dnd-kit/sortable'
import { CSS } from '@dnd-kit/utilities'

import api from '../../utils/api'
import { useApi } from '../../hooks/useApi'
import { useToast } from '../../hooks/useToast'
import {
  ToastContainer, Button, Modal, FormGroup, Toggle,
  ColorPicker, ImageUpload, Alert, DragHandle,
  EmptyState, Spinner, ConfirmDialog,
} from '../../components/common'
import { useBuilderSticky, BuilderToolbar } from '../../components/BuilderMobileUi'

/* ── Section type definitions ───────────────────────────────────────── */
const SECTION_TYPES = [
  // FIXED: 'hero' and 'marquee' removed from this list. Traced the real
  // public page (src/pages/public/ServiceDetailPage.tsx:689) and
  // confirmed the service page's actual hero/marquee rendering is driven
  // directly by svc.hero_settings/svc.marquee_settings (the dedicated
  // Hero Settings / Marquee Settings panels elsewhere in this builder,
  // both already correctly wired end-to-end) — NOT by this generic
  // sections array at all. Adding a "Hero Section" or "Marquee Banner"
  // via this dropdown previously saved successfully with no error, but
  // never appeared anywhere on the real page, since the public renderer
  // has no case for these types in its per-section switch. Removed here
  // to stop creating confusing, silently-non-functional sections; the
  // dedicated settings panels are the correct, working place to edit
  // these.
  { value: 'description',  label: 'Description',         icon: '📄',  desc: 'Rich text description of the service' },
  { value: 'process',      label: 'Process Steps',       icon: '⚙️',  desc: 'Step-by-step how it works' },
  { value: 'documents',    label: 'Required Documents',  icon: '📎',  desc: 'List of documents needed' },
  { value: 'why_choose',   label: 'Why Choose Us',       icon: '⭐',  desc: 'Feature cards with icons' },
  { value: 'trust_badges', label: 'Trust Badges',        icon: '🏆',  desc: 'Statistics and trust indicators' },
  { value: 'faq',          label: 'FAQ',                 icon: '❓',  desc: 'Frequently asked questions' },
  { value: 'eligibility',  label: 'Eligibility',         icon: '✅',  desc: 'Who is eligible for this service' },
  { value: 'charges',      label: 'Charges',             icon: '💰',  desc: 'Service charges and fees' },
  { value: 'security',     label: 'Security Note',       icon: '🔒',  desc: 'Data security assurance' },
  { value: 'benefits',     label: 'Benefits',            icon: '🎯',  desc: 'Benefits list' },
  { value: 'features',     label: 'Features',            icon: '✨',  desc: 'Feature highlights' },
  { value: 'cta',          label: 'Call to Action',      icon: '🚀',  desc: 'Action button section' },
  { value: 'testimonials', label: 'Testimonials',        icon: '💬',  desc: 'Customer reviews' },
  { value: 'text',         label: 'Custom Text',         icon: '📝',  desc: 'Free-form HTML content' },
  { value: 'notes',        label: 'Notes',               icon: 'ℹ️',  desc: 'Important notes or disclaimers' },
  { value: 'highlights',   label: 'Highlights',          icon: '💡',  desc: 'Key highlights grid' },
  { value: 'related',      label: 'Related Services',    icon: '🔗',  desc: 'Links to related services' },
]

const BUILDER_TABS = ['sections', 'hero', 'marquee', 'nav']

/* ── Main Service Builder Page ──────────────────────────────────────── */
export default function ServiceBuilderPage() {
  const urlServiceId = (() => {
    const v = new URLSearchParams(window.location.search).get('service')
    return v ? parseInt(v, 10) : null
  })()
  const urlTab = (() => {
    const t = new URLSearchParams(window.location.search).get('tab')
    return t && BUILDER_TABS.includes(t) ? t : 'sections'
  })()

  const [selectedServiceId, setSelectedServiceId] = useState(urlServiceId)
  const [activeTab, setActiveTab] = useState(urlTab)
  const { data: svcsData, loading: svcsLoading } = useApi('admin/services', { per_page: 200 })
  const services = svcsData?.services || []
  const selectedSvc = services.find(s => s.id === selectedServiceId)
  const siteUrl = (window.S2NRI_BUILDER?.siteUrl || '/').replace(/\/$/, '')
  const previewHref = selectedSvc?.slug ? `${siteUrl}/service/${selectedSvc.slug}` : siteUrl

  const previewSticky = useMemo(
    () => (selectedServiceId ? (
      <a href={previewHref} target="_blank" rel="noreferrer" className="btn btn-primary btn-lg s2-builder-sticky-primary">
        Preview Service ↗
      </a>
    ) : null),
    [selectedServiceId, previewHref],
  )
  useBuilderSticky(previewSticky)

  return (
    <div>
      <div className="page-header">
        <div>
          <h1>Service Page Builder</h1>
          <p>Design the Hero section, Marquee, and all content sections for each service page. The left-side section navigation is generated automatically.</p>
        </div>
      </div>

      {svcsLoading ? <Spinner /> : (
        <div className="s2builder-service-grid" style={{ display: 'grid', gridTemplateColumns: '260px 1fr', gap: 20, alignItems: 'flex-start' }}>
          {/* Service list */}
          <div className="card">
            <div className="card-header">
              <h3 className="card-title">Services</h3>
              <span style={{ fontSize: 12, color: 'var(--text-muted)' }}>{services.length}</span>
            </div>
            <div style={{ maxHeight: 620, overflowY: 'auto' }}>
              {services.map(svc => (
                <button
                  key={svc.id}
                  onClick={() => { setSelectedServiceId(svc.id); setActiveTab('sections') }}
                  style={{
                    width: '100%', padding: '10px 16px', border: 'none',
                    borderBottom: '1px solid var(--border)',
                    background: selectedServiceId === svc.id ? 'var(--brand-light)' : 'transparent',
                    color: selectedServiceId === svc.id ? 'var(--brand)' : 'var(--text)',
                    fontWeight: selectedServiceId === svc.id ? 700 : 400,
                    cursor: 'pointer', textAlign: 'left', fontSize: 13,
                    display: 'flex', alignItems: 'center', gap: 8,
                  }}
                >
                  <span>{svc.icon || '📄'}</span>
                  <span style={{ flex: 1 }}>{svc.name}</span>
                </button>
              ))}
            </div>
          </div>

          {/* Editor panel */}
          {selectedServiceId ? (
            <div>
              {/* Sub-tabs */}
              <BuilderToolbar className="s2builder-service-tabs">
                {[
                  ['sections', '📋 Sections'],
                  ['hero',     '🖼️ Hero Settings'],
                  ['marquee',  '📢 Marquee'],
                  ['nav',      '🗂️ Section Nav'],
                ].map(([key, label]) => (
                  <button key={key} type="button" className={`tab-btn ${activeTab === key ? 'active' : ''}`}
                    onClick={() => setActiveTab(key)}>
                    {label}
                  </button>
                ))}
              </BuilderToolbar>

              {activeTab === 'sections' && (
                <SectionsEditor
                  serviceId={selectedServiceId}
                  serviceName={services.find(s => s.id === selectedServiceId)?.name}
                />
              )}
              {activeTab === 'hero' && (
                <HeroEditor serviceId={selectedServiceId} />
              )}
              {activeTab === 'marquee' && (
                <MarqueeEditor serviceId={selectedServiceId} />
              )}
              {activeTab === 'nav' && (
                <SectionNavPreview serviceId={selectedServiceId} />
              )}
            </div>
          ) : (
            <div className="card">
              <EmptyState icon="👈" title="Select a service" description="Choose a service to edit its page sections" />
            </div>
          )}
        </div>
      )}
    </div>
  )
}

/* ── Sections Editor ────────────────────────────────────────────────── */
function SectionsEditor({ serviceId, serviceName }) {
  const [editingSection, setEditingSection] = useState(null)
  const [addingType, setAddingType] = useState(false)
  const [confirmDelete, setConfirmDelete] = useState(null)
  const { toasts, toast } = useToast()
  const { data, loading, reload, setData } = useApi(`admin/services/${serviceId}/sections`)
  const sections = (data?.sections || []).sort((a, b) => Number(a.sort_order) - Number(b.sort_order))

  const sensors = useSensors(
    useSensor(PointerSensor),
    useSensor(KeyboardSensor, { coordinateGetter: sortableKeyboardCoordinates })
  )

  async function handleDragEnd({ active, over }) {
    if (!over || active.id === over.id) return
    const oldIdx = sections.findIndex(s => String(s.id) === String(active.id))
    const newIdx = sections.findIndex(s => String(s.id) === String(over.id))
    const reordered = arrayMove(sections, oldIdx, newIdx).map((s, i) => ({ ...s, sort_order: i + 1 }))
    setData({ ...data, sections: reordered })
    try {
      await api.put(`admin/services/${serviceId}/sections/reorder`, {
        order: reordered.map((s, i) => ({ id: s.id, sort_order: i + 1 }))
      })
    } catch { toast.error('Failed to save order'); reload() }
  }

  async function addSection(type) {
    const typeInfo = SECTION_TYPES.find(t => t.value === type)
    try {
      await api.post(`admin/services/${serviceId}/sections`, {
        type,
        title: typeInfo?.label || type,
        content: defaultContent(type),
        is_active: true,
        sort_order: sections.length + 1,
      })
      toast.success(`${typeInfo?.label} added`)
      reload()
      setAddingType(false)
    } catch (err) { toast.error(err.message) }
  }

  async function deleteSection(id) {
    try {
      await api.delete(`admin/services/${serviceId}/sections/${id}`)
      toast.success('Section deleted')
      setConfirmDelete(null)
      reload()
    } catch (err) { toast.error(err.message) }
  }

  async function toggleSection(section) {
    try {
      await api.patch(`admin/services/${serviceId}/sections/${section.id}/toggle`, {})
      reload()
    } catch (err) { toast.error(err.message) }
  }

  async function duplicateSection(id) {
    try {
      await api.post(`admin/services/${serviceId}/sections/${id}/duplicate`, {})
      toast.success('Section duplicated')
      reload()
    } catch (err) { toast.error(err.message) }
  }

  if (loading) return <div className="card"><Spinner /></div>

  return (
    <>
      <ToastContainer toasts={toasts} />
      <div className="card">
        <div className="card-header">
          <div>
            <h3 className="card-title">Page Sections — {serviceName}</h3>
            <p style={{ margin: 0, fontSize: 12, color: 'var(--text-muted)' }}>
              {sections.length} section{sections.length !== 1 ? 's' : ''} • Drag to reorder
            </p>
          </div>
          <Button variant="primary" size="sm" onClick={() => setAddingType(true)}>+ Add Section</Button>
        </div>

        <div className="card-body">
          {sections.length === 0 ? (
            <EmptyState icon="📄" title="No sections yet"
              description="Add sections to build this service page"
              action={<Button variant="primary" onClick={() => setAddingType(true)}>Add First Section</Button>} />
          ) : (
            <DndContext sensors={sensors} collisionDetection={closestCenter} onDragEnd={handleDragEnd}>
              <SortableContext items={sections.map(s => String(s.id))} strategy={verticalListSortingStrategy}>
                <div style={{ display: 'flex', flexDirection: 'column', gap: 8 }}>
                  {sections.map(sec => (
                    <SortableSection key={sec.id} section={sec}
                      onEdit={() => setEditingSection({ ...sec })}
                      onDelete={() => setConfirmDelete(sec)}
                      onToggle={() => toggleSection(sec)}
                      onDuplicate={() => duplicateSection(sec.id)} />
                  ))}
                </div>
              </SortableContext>
            </DndContext>
          )}
        </div>
      </div>

      {/* Add section type picker */}
      <Modal open={addingType} onClose={() => setAddingType(false)} title="Choose Section Type" size="lg">
        <div style={{ display: 'grid', gridTemplateColumns: 'repeat(auto-fill, minmax(200px, 1fr))', gap: 12 }}>
          {SECTION_TYPES.map(type => (
            <button key={type.value} onClick={() => addSection(type.value)}
              style={{
                padding: '14px 16px', border: '1px solid var(--border)', borderRadius: 8,
                background: 'var(--surface)', cursor: 'pointer', textAlign: 'left',
                transition: 'all 0.15s',
              }}
              onMouseEnter={e => { e.currentTarget.style.borderColor = 'var(--brand)'; e.currentTarget.style.background = 'var(--brand-light)' }}
              onMouseLeave={e => { e.currentTarget.style.borderColor = 'var(--border)'; e.currentTarget.style.background = 'var(--surface)' }}
            >
              <div style={{ fontSize: 22, marginBottom: 6 }}>{type.icon}</div>
              <div style={{ fontWeight: 700, fontSize: 13, marginBottom: 3 }}>{type.label}</div>
              <div style={{ fontSize: 11, color: 'var(--text-muted)', lineHeight: 1.4 }}>{type.desc}</div>
            </button>
          ))}
        </div>
      </Modal>

      {/* Section content editor */}
      {editingSection && (
        <SectionContentEditor
          section={editingSection}
          serviceId={serviceId}
          onClose={() => setEditingSection(null)}
          onSaved={() => { setEditingSection(null); reload() }}
        />
      )}

      <ConfirmDialog open={!!confirmDelete} onCancel={() => setConfirmDelete(null)}
        onConfirm={() => deleteSection(confirmDelete?.id)}
        title="Delete Section" danger confirmLabel="Delete Section"
        message={`Delete "${confirmDelete?.title || confirmDelete?.type}" section? This cannot be undone.`} />
    </>
  )
}

/* ── Sortable Section Row ───────────────────────────────────────────── */
function SortableSection({ section, onEdit, onDelete, onToggle, onDuplicate }) {
  const { attributes, listeners, setNodeRef, transform, transition, isDragging } = useSortable({ id: String(section.id) })
  const typeInfo = SECTION_TYPES.find(t => t.value === section.type)

  return (
    <div ref={setNodeRef} style={{
      transform: CSS.Transform.toString(transform), transition,
      opacity: isDragging ? 0.4 : section.is_active ? 1 : 0.5,
      display: 'flex', alignItems: 'center', gap: 10,
      padding: '10px 12px', border: '1px solid var(--border)',
      borderRadius: 8, background: section.is_active ? 'var(--surface)' : 'var(--surface-3)',
    }}>
      <DragHandle {...attributes} {...listeners} />
      <span style={{ fontSize: 18, width: 24, textAlign: 'center' }}>{typeInfo?.icon || '📄'}</span>
      <div style={{ flex: 1, minWidth: 0 }}>
        <div style={{ fontWeight: 600, fontSize: 13 }}>{section.title || typeInfo?.label || section.type}</div>
        <div style={{ fontSize: 11, color: 'var(--text-muted)' }}>{typeInfo?.label || section.type}</div>
      </div>
      <Toggle checked={!!section.is_active} onChange={onToggle} />
      <Button variant="ghost" size="sm" onClick={onDuplicate} title="Duplicate">⧉</Button>
      <Button variant="secondary" size="sm" onClick={onEdit}>Edit</Button>
      <Button variant="ghost" size="sm" onClick={onDelete} style={{ color: 'var(--red)' }}>✕</Button>
    </div>
  )
}

/* ── Section Content Editor ─────────────────────────────────────────── */
function SectionContentEditor({ section, serviceId, onClose, onSaved }) {
  const [form, setForm] = useState({ ...section, content: section.content || {} })
  const [saving, setSaving] = useState(false)
  const { toast } = useToast()

  const set = (key, val) => setForm(f => ({ ...f, [key]: val }))
  const setContent = (key, val) => setForm(f => ({ ...f, content: { ...f.content, [key]: val } }))

  // Device visibility stored in content.device_visibility
  const dv = form.content?.device_visibility || { desktop: true, tablet: true, mobile: true }
  const setDV = (device, val) => setContent('device_visibility', { ...dv, [device]: val })

  async function save() {
    setSaving(true)
    try {
      await api.put(`admin/services/${serviceId}/sections/${section.id}`, form)
      toast.success('Section saved')
      onSaved()
    } catch (err) { toast.error(err.message) }
    finally { setSaving(false) }
  }

  const typeInfo = SECTION_TYPES.find(t => t.value === section.type)

  return (
    <Modal open onClose={onClose} title={`Edit — ${typeInfo?.icon} ${typeInfo?.label || section.type}`} size="xl"
      footer={<>
        <Button variant="secondary" onClick={onClose}>Cancel</Button>
        <Button variant="primary" loading={saving} onClick={save}>Save Section</Button>
      </>}>
      <FormGroup label="Section Title">
        <input className="form-input" value={form.title || ''} onChange={e => set('title', e.target.value)}
          placeholder={typeInfo?.label || 'Section title'} />
      </FormGroup>

      <div style={{ display: 'flex', gap: 16, marginBottom: 20, flexWrap: 'wrap' }}>
        <Toggle checked={!!form.is_active} onChange={v => set('is_active', v)} label="Active (visible on site)" />
      </div>

      {/* Responsive visibility controls */}
      <div style={{
        background: 'var(--surface-2)', border: '1px solid var(--border)',
        borderRadius: 8, padding: '14px 16px', marginBottom: 20,
      }}>
        <div style={{ fontSize: 12, fontWeight: 700, color: 'var(--text-muted)', textTransform: 'uppercase', letterSpacing: 1, marginBottom: 12 }}>
          📱 Device Visibility
        </div>
        <div style={{ display: 'flex', gap: 16, flexWrap: 'wrap' }}>
          {[
            { key: 'desktop', label: '🖥️ Desktop', hint: 'Show on screens > 1024px' },
            { key: 'tablet',  label: '📱 Tablet',  hint: 'Show on 768–1023px' },
            { key: 'mobile',  label: '📲 Mobile',  hint: 'Show on screens < 768px' },
          ].map(({ key, label, hint }) => (
            <label key={key} style={{
              display: 'flex', alignItems: 'center', gap: 10, cursor: 'pointer',
              padding: '10px 16px', background: dv[key] !== false ? 'var(--brand-light)' : 'var(--surface)',
              border: `1px solid ${dv[key] !== false ? 'var(--brand)' : 'var(--border)'}`,
              borderRadius: 8, minWidth: 160, flex: 1,
            }}>
              <div onClick={() => setDV(key, dv[key] === false ? true : false)} style={{
                width: 36, height: 20, borderRadius: 99,
                background: dv[key] !== false ? 'var(--brand)' : 'var(--border)',
                position: 'relative', cursor: 'pointer', transition: 'background 0.15s', flexShrink: 0,
              }}>
                <div style={{
                  position: 'absolute', top: 2, left: dv[key] !== false ? 18 : 2,
                  width: 16, height: 16, borderRadius: '50%', background: '#fff',
                  transition: 'left 0.15s',
                }} />
              </div>
              <div>
                <div style={{ fontWeight: 600, fontSize: 13 }}>{label}</div>
                <div style={{ fontSize: 11, color: 'var(--text-muted)' }}>{hint}</div>
              </div>
            </label>
          ))}
        </div>
      </div>

      {/* Type-specific content fields */}
      <SectionContentFields type={section.type} content={form.content || {}} onChange={setContent} />
    </Modal>
  )
}

/* ── Type-specific content fields ───────────────────────────────────── */
function SectionContentFields({ type, content, onChange }) {
  switch (type) {
    case 'description':
    case 'text':
    case 'notes':
    case 'security':
      return (
        <>
          <FormGroup label="Heading">
            <input className="form-input" value={content.heading || ''} onChange={e => onChange('heading', e.target.value)} />
          </FormGroup>
          <FormGroup label="Content (HTML supported)" hint="You can use <strong>, <ul>, <li>, <a href=''> etc.">
            <textarea className="form-textarea" rows={8} value={content.html || ''}
              onChange={e => onChange('html', e.target.value)} />
          </FormGroup>
        </>
      )

    case 'process':
      return <ListEditor label="Steps" items={content.steps || []} onChange={v => onChange('steps', v)}
        template={{ icon: '1', title: '', desc: '' }}
        fields={[{ key: 'icon', label: 'Icon/Number', width: '80px' }, { key: 'title', label: 'Title' }, { key: 'desc', label: 'Description' }]} />

    case 'why_choose':
      return <ListEditor label="Feature Cards" items={content.cards || []} onChange={v => onChange('cards', v)}
        template={{ icon: '✅', title: '', desc: '' }}
        fields={[{ key: 'icon', label: 'Icon', width: '60px' }, { key: 'title', label: 'Title' }, { key: 'desc', label: 'Description' }]} />

    case 'trust_badges':
      return <ListEditor label="Badges" items={content.badges || []} onChange={v => onChange('badges', v)}
        template={{ icon: '🏆', value: '', label: '' }}
        fields={[{ key: 'icon', label: 'Icon', width: '60px' }, { key: 'value', label: 'Value' }, { key: 'label', label: 'Label' }]} />

    case 'faq':
      return <ListEditor label="FAQ Items" items={content.items || []} onChange={v => onChange('items', v)}
        template={{ question: '', answer: '' }}
        fields={[{ key: 'question', label: 'Question' }, { key: 'answer', label: 'Answer' }]} />

    case 'documents':
      return <ListEditor label="Required Documents" items={content.items || []} onChange={v => onChange('items', v)}
        template={{ name: '', desc: '', required: true }}
        fields={[{ key: 'name', label: 'Document Name' }, { key: 'desc', label: 'Description' }]} />

    case 'benefits':
    case 'features':
    case 'highlights':
      return <ListEditor label="Items" items={content.items || []} onChange={v => onChange('items', v)}
        template={{ icon: '✅', title: '', desc: '' }}
        fields={[{ key: 'icon', label: 'Icon', width: '60px' }, { key: 'title', label: 'Title' }, { key: 'desc', label: 'Description' }]} />

    case 'charges':
      return (
        <>
          <ListEditor label="Charge Items" items={content.items || []} onChange={v => onChange('items', v)}
            template={{ label: '', amount: '', note: '' }}
            fields={[{ key: 'label', label: 'Item' }, { key: 'amount', label: 'Amount' }, { key: 'note', label: 'Note' }]} />
          <FormGroup label="Additional Note">
            <textarea className="form-textarea" rows={2} value={content.note || ''} onChange={e => onChange('note', e.target.value)} />
          </FormGroup>
        </>
      )

    case 'eligibility':
      return (
        <>
          <FormGroup label="Eligible For">
            <textarea className="form-textarea" rows={3} value={content.eligible || ''} onChange={e => onChange('eligible', e.target.value)} placeholder="List who is eligible..." />
          </FormGroup>
          <FormGroup label="Not Eligible For">
            <textarea className="form-textarea" rows={3} value={content.not_eligible || ''} onChange={e => onChange('not_eligible', e.target.value)} placeholder="List who is not eligible..." />
          </FormGroup>
        </>
      )

    case 'cta':
      return (
        <>
          <FormGroup label="CTA Heading">
            <input className="form-input" value={content.heading || ''} onChange={e => onChange('heading', e.target.value)} />
          </FormGroup>
          <FormGroup label="CTA Description">
            <textarea className="form-textarea" rows={2} value={content.description || ''} onChange={e => onChange('description', e.target.value)} />
          </FormGroup>
          <div className="form-row cols-2">
            <FormGroup label="Button Text">
              <input className="form-input" value={content.button_text || ''} onChange={e => onChange('button_text', e.target.value)} placeholder="Get Started" />
            </FormGroup>
            <FormGroup label="Button URL">
              <input className="form-input" value={content.button_url || ''} onChange={e => onChange('button_url', e.target.value)} placeholder="/book/service-slug" />
            </FormGroup>
          </div>
        </>
      )

    default:
      return (
        <Alert type="info">
          This section type ({type}) uses default content. Title is shown and content is auto-generated from service data.
        </Alert>
      )
  }
}

/* ── Reusable List Editor ───────────────────────────────────────────── */
function ListEditor({ label, items, onChange, template, fields }) {
  function add() { onChange([...items, { ...template }]) }
  function remove(i) { onChange(items.filter((_, idx) => idx !== i)) }
  function update(i, key, val) {
    const next = [...items]
    next[i] = { ...next[i], [key]: val }
    onChange(next)
  }

  return (
    <div style={{ marginBottom: 16 }}>
      <div style={{ display: 'flex', justifyContent: 'space-between', alignItems: 'center', marginBottom: 8 }}>
        <label className="form-label">{label}</label>
        <Button variant="secondary" size="sm" onClick={add}>+ Add</Button>
      </div>
      {items.length === 0 && (
        <p style={{ fontSize: 12, color: 'var(--text-muted)', margin: '8px 0' }}>No items yet. Click + Add to add the first one.</p>
      )}
      {items.map((item, i) => (
        <div key={i} style={{ display: 'flex', gap: 8, marginBottom: 8, alignItems: 'flex-start' }}>
          {fields.map(f => (
            <input key={f.key} className="form-input" value={item[f.key] || ''} placeholder={f.label}
              onChange={e => update(i, f.key, e.target.value)}
              style={{ width: f.width || undefined, flex: f.width ? undefined : 1 }} />
          ))}
          <Button variant="ghost" size="sm" onClick={() => remove(i)} style={{ color: 'var(--red)', flexShrink: 0, marginTop: 2 }}>✕</Button>
        </div>
      ))}
    </div>
  )
}

const HERO_WIDTH_PRESETS = ['1200px', '1100px', '960px', '860px', '720px']

/* ── Hero Editor ────────────────────────────────────────────────────── */
function HeroEditor({ serviceId }) {
  const { toasts, toast } = useToast()
  const [settings, setSettings] = useState({})
  const [saving, setSaving] = useState(false)
  const [loading, setLoading] = useState(true)

  const key = `service_hero_${serviceId}`

  useEffect(() => {
    api.get(`admin/services/${serviceId}`)
      .then(res => {
        const hero = res.service?.hero_settings || {}
        setSettings(hero)
      })
      .catch(err => toast.error(err.message))
      .finally(() => setLoading(false))
  }, [serviceId])

  const set = (k, v) => setSettings(s => ({ ...s, [k]: v }))

  async function save() {
    setSaving(true)
    try {
      const hero = { ...settings }
      const w = String(hero.container_max || hero.content_max || '').trim()
      if (w) {
        hero.container_max = w
        delete hero.content_max
      } else {
        delete hero.container_max
        delete hero.content_max
      }
      await api.put(`admin/services/${serviceId}`, { hero_settings: hero })
      setSettings(hero)
      toast.success('Hero settings saved')
    } catch (err) { toast.error(err.message) }
    finally { setSaving(false) }
  }

  const heroMaxWidth = String(settings.container_max || settings.content_max || '').trim()
  const widthSelectValue = !heroMaxWidth
    ? ''
    : HERO_WIDTH_PRESETS.includes(heroMaxWidth)
      ? heroMaxWidth
      : '__custom__'

  function setHeroMaxWidth(next) {
    setSettings(s => {
      const copy = { ...s }
      if (!next) {
        delete copy.container_max
        delete copy.content_max
      } else {
        copy.container_max = next
        delete copy.content_max
      }
      return copy
    })
  }

  if (loading) return <Spinner />

  return (
    <>
      <ToastContainer toasts={toasts} />
      <div className="card">
        <div className="card-header">
          <h3 className="card-title">🖼️ Hero Section Settings</h3>
          <Button variant="primary" loading={saving} onClick={save}>Save Hero</Button>
        </div>
        <div className="card-body">
          <Alert type="info" style={{ marginBottom: 20 }}>
            These settings override the default service hero for this specific service. Leave fields blank to use the platform defaults.
          </Alert>

          <Toggle checked={settings.enabled !== false} onChange={v => set('enabled', v)} label="Enable custom hero for this service" />
          <div style={{ height: 16 }} />

          <FormGroup
            label="Hero content max width"
            hint="Overrides Design System → Width & Layout → service section → hero for this service only. Leave as inherit to use global/section defaults."
          >
            <select
              className="form-select"
              value={widthSelectValue}
              onChange={e => {
                const v = e.target.value
                if (v === '__custom__') {
                  if (!heroMaxWidth) setHeroMaxWidth('960px')
                } else {
                  setHeroMaxWidth(v)
                }
              }}
            >
              <option value="">Inherit from Width & Layout</option>
              {HERO_WIDTH_PRESETS.map(px => (
                <option key={px} value={px}>{px} — {px === '1200px' ? 'page max' : px === '1100px' ? 'section standard' : px === '960px' ? 'content' : px === '860px' ? 'inner' : 'narrow prose'}</option>
              ))}
              <option value="__custom__">Custom…</option>
            </select>
            {widthSelectValue === '__custom__' && (
              <input
                className="form-input"
                style={{ marginTop: 8 }}
                value={heroMaxWidth}
                onChange={e => setHeroMaxWidth(e.target.value.trim())}
                placeholder="e.g. 1040px or min(100%, 900px)"
              />
            )}
          </FormGroup>

          <div style={{ height: 16 }} />

          <ImageUpload label="Hero Background Image" value={settings.image_url || ''} onChange={v => set('image_url', v)} hint="Recommended: 1400×500px JPG or WebP" />

          <div className="form-row cols-2">
            <ColorPicker label="Overlay Color" value={settings.overlay_color || '#000000'} onChange={v => set('overlay_color', v)} />
            <FormGroup label="Overlay Opacity (0–100)">
              <input type="range" min="0" max="100" value={settings.overlay_opacity || 40}
                onChange={e => set('overlay_opacity', Number(e.target.value))}
                style={{ width: '100%' }} />
              <div style={{ fontSize: 12, color: 'var(--text-muted)', marginTop: 4 }}>{settings.overlay_opacity || 40}%</div>
            </FormGroup>
          </div>

          <FormGroup label="Hero Title" hint="Leave blank to use service name">
            <input className="form-input" value={settings.title || ''} onChange={e => set('title', e.target.value)} placeholder="Override service name in hero" />
          </FormGroup>

          <FormGroup label="Hero Subtitle">
            <textarea className="form-textarea" rows={2} value={settings.subtitle || ''} onChange={e => set('subtitle', e.target.value)} placeholder="Short description shown below the title" />
          </FormGroup>

          <div className="form-row cols-2">
            <FormGroup label="Primary CTA Text">
              <input className="form-input" value={settings.cta_text || ''} onChange={e => set('cta_text', e.target.value)} placeholder="Apply Now" />
            </FormGroup>
            <FormGroup label="Primary CTA URL">
              <input className="form-input" value={settings.cta_url || ''} onChange={e => set('cta_url', e.target.value)} placeholder="/book/service-slug" />
            </FormGroup>
          </div>

          <div className="form-row cols-2">
            <FormGroup label="Secondary CTA Text">
              <input className="form-input" value={settings.cta2_text || ''} onChange={e => set('cta2_text', e.target.value)} placeholder="Learn More" />
            </FormGroup>
            <FormGroup label="Secondary CTA URL">
              <input className="form-input" value={settings.cta2_url || ''} onChange={e => set('cta2_url', e.target.value)} placeholder="#description" />
            </FormGroup>
          </div>

          <div className="form-row cols-2">
            <FormGroup label="Hero Height (px)">
              <input type="number" className="form-input" value={settings.height || 400} onChange={e => set('height', Number(e.target.value))} min={200} max={800} />
            </FormGroup>
            <FormGroup label="Text Alignment">
              <select className="form-select" value={settings.text_align || 'left'} onChange={e => set('text_align', e.target.value)}>
                <option value="left">Left</option>
                <option value="center">Center</option>
                <option value="right">Right</option>
              </select>
            </FormGroup>
          </div>

          <Toggle checked={!!settings.show_breadcrumb} onChange={v => set('show_breadcrumb', v)} label="Show breadcrumb navigation" />
          <div style={{ height: 8 }} />
          <Toggle checked={!!settings.show_category_badge} onChange={v => set('show_category_badge', v)} label="Show category badge" />
        </div>
      </div>
    </>
  )
}

/* ── Marquee Editor ─────────────────────────────────────────────────── */
function MarqueeEditor({ serviceId }) {
  const { toasts, toast } = useToast()
  const [settings, setSettings] = useState(null)
  const [saving, setSaving] = useState(false)
  const [loading, setLoading] = useState(true)

  useEffect(() => {
    let cancelled = false
    setLoading(true)
    setSettings(null) // reset on serviceId change
    api.get(`admin/services/${serviceId}`)
      .then(res => {
        if (cancelled) return
        setSettings(res.service?.marquee_settings || {
          enabled: true, text: '', speed: 30, bg_color: '#0d7ab5',
          text_color: '#ffffff', pause_hover: true,
        })
      })
      .catch(err => {
        if (cancelled) return
        toast.error(err.message || 'Failed to load marquee settings')
        setSettings({ enabled: true, text: '', speed: 30, bg_color: '#0d7ab5', text_color: '#ffffff', pause_hover: true })
      })
      .finally(() => { if (!cancelled) setLoading(false) })
    return () => { cancelled = true }
  }, [serviceId])

  const set = (k, v) => setSettings(s => ({ ...s, [k]: v }))

  async function save() {
    setSaving(true)
    try {
      await api.put(`admin/services/${serviceId}`, { marquee_settings: settings })
      toast.success('Marquee settings saved')
    } catch (err) { toast.error(err.message) }
    finally { setSaving(false) }
  }

  if (loading || !settings) return <Spinner />

  return (
    <>
      <ToastContainer toasts={toasts} />
      <div className="card">
        <div className="card-header">
          <h3 className="card-title">📢 Marquee Settings</h3>
          <Button variant="primary" loading={saving} onClick={save}>Save Marquee</Button>
        </div>
        <div className="card-body">
          <Toggle checked={!!settings.enabled} onChange={v => set('enabled', v)} label="Enable marquee for this service page" />
          <div style={{ height: 20 }} />

          {settings.enabled && (
            <>
              {/* Live preview */}
              <div style={{
                overflow: 'hidden', background: settings.bg_color || '#0d7ab5',
                color: settings.text_color || '#fff', padding: '10px 0',
                borderRadius: 8, marginBottom: 20, fontSize: 14, fontWeight: 600,
              }}>
                <div style={{ display: 'flex', animation: `marquee ${settings.speed || 30}s linear infinite`, whiteSpace: 'nowrap' }}>
                  <span style={{ paddingRight: 80 }}>{settings.text || '✓ Fast Service  ✓ Secure  ✓ Expert Assistance'}</span>
                  <span style={{ paddingRight: 80 }}>{settings.text || '✓ Fast Service  ✓ Secure  ✓ Expert Assistance'}</span>
                </div>
                <style>{`@keyframes marquee { from { transform: translateX(0) } to { transform: translateX(-50%) } }`}</style>
              </div>

              <FormGroup label="Marquee Text" hint="Use spaces or separators like ✓ or • between items">
                <textarea className="form-textarea" rows={3} value={settings.text || ''}
                  onChange={e => set('text', e.target.value)}
                  placeholder="✓ Fast Service  ✓ 100% Secure  ✓ Expert Assistance  ✓ 24/7 Support" />
              </FormGroup>

              <div className="form-row cols-2">
                <FormGroup label="Animation Speed (seconds)" hint="Lower = faster. Recommended: 20–60">
                  <input type="number" className="form-input" value={settings.speed || 30}
                    onChange={e => set('speed', Number(e.target.value))} min={5} max={120} />
                </FormGroup>
              </div>

              <div className="form-row cols-2">
                <ColorPicker label="Background Color" value={settings.bg_color || '#0d7ab5'} onChange={v => set('bg_color', v)} />
                <ColorPicker label="Text Color" value={settings.text_color || '#ffffff'} onChange={v => set('text_color', v)} />
              </div>

              <Toggle checked={!!settings.pause_hover} onChange={v => set('pause_hover', v)} label="Pause animation on hover" />
            </>
          )}
        </div>
      </div>
    </>
  )
}

/* ── Section Nav Preview ─────────────────────────────────────────────── */
function SectionNavPreview({ serviceId }) {
  const { data, loading } = useApi(`admin/services/${serviceId}/sections`)
  const sections = (data?.sections || []).filter(s => s.is_active).sort((a, b) => Number(a.sort_order) - Number(b.sort_order))

  if (loading) return <Spinner />

  return (
    <div className="card">
      <div className="card-header">
        <h3 className="card-title">🗂️ Section Navigation Preview</h3>
      </div>
      <div className="card-body">
        <Alert type="info" style={{ marginBottom: 20 }}>
          The left-side navigation menu is automatically generated from your active sections. It updates live whenever you add, remove, or reorder sections.
        </Alert>

        {/* Preview */}
        <div style={{ display: 'grid', gridTemplateColumns: '200px 1fr', gap: 20, border: '1px solid var(--border)', borderRadius: 8, overflow: 'hidden' }}>
          {/* Nav panel */}
          <div style={{ background: '#F5F7FA', borderRight: '1px solid var(--border)', padding: 16 }}>
            <div style={{ fontSize: 11, fontWeight: 700, color: 'var(--text-muted)', textTransform: 'uppercase', letterSpacing: 1, marginBottom: 12 }}>
              On This Page
            </div>
            {sections.length === 0 ? (
              <p style={{ fontSize: 12, color: 'var(--text-muted)' }}>No active sections</p>
            ) : (
              sections.map((sec, i) => {
                const typeInfo = SECTION_TYPES.find(t => t.value === sec.type)
                return (
                  <div key={sec.id} style={{
                    padding: '6px 10px', marginBottom: 2, borderRadius: 6,
                    fontSize: 12, color: i === 0 ? 'var(--brand)' : 'var(--text-muted)',
                    background: i === 0 ? 'var(--brand-light)' : 'transparent',
                    fontWeight: i === 0 ? 700 : 400,
                    display: 'flex', alignItems: 'center', gap: 6,
                    cursor: 'pointer',
                  }}>
                    <span>{typeInfo?.icon || '📄'}</span>
                    <span>{sec.title || typeInfo?.label || sec.type}</span>
                  </div>
                )
              })
            )}
          </div>

          {/* Content area preview */}
          <div style={{ padding: 16 }}>
            <div style={{ fontSize: 13, color: 'var(--text-muted)', marginBottom: 12 }}>
              <em>Service page content appears here</em>
            </div>
            {sections.map((sec) => {
              const typeInfo = SECTION_TYPES.find(t => t.value === sec.type)
              return (
                <div key={sec.id} style={{ padding: '8px 12px', marginBottom: 8, border: '1px dashed var(--border)', borderRadius: 6, fontSize: 12 }}>
                  {typeInfo?.icon} <strong>{sec.title || typeInfo?.label}</strong>
                </div>
              )
            })}
          </div>
        </div>

        <div style={{ marginTop: 16, fontSize: 12, color: 'var(--text-muted)' }}>
          <strong>How it works:</strong> Each active section generates a nav link. Clicking a link smoothly scrolls to that section.
          Sections with <code>hero</code> and <code>marquee</code> types are excluded from the nav links.
          To reorder nav items, reorder the sections in the Sections tab.
        </div>
      </div>
    </div>
  )
}

/* ── Default content for new sections ──────────────────────────────── */
function defaultContent(type) {
  const defaults = {
    process:      { steps: [{ icon: '1', title: 'Step 1', desc: 'Description' }] },
    why_choose:   { cards: [{ icon: '✅', title: 'Feature', desc: 'Description' }] },
    trust_badges: { badges: [{ icon: '⭐', value: '4.9', label: 'Rating' }] },
    faq:          { items: [{ question: 'Question?', answer: 'Answer.' }] },
    documents:    { items: [{ name: 'Document Name', desc: 'Description', required: true }] },
    benefits:     { items: [{ icon: '✅', title: 'Benefit', desc: 'Description' }] },
    description:  { heading: 'About This Service', html: '<p>Describe the service here.</p>' },
    cta:          { heading: 'Ready to Get Started?', button_text: 'Apply Now', button_url: '#' },
  }
  return defaults[type] || {}
}
