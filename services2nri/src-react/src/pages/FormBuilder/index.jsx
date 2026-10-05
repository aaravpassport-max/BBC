import { useState, useCallback, useMemo } from 'react'
import {
  DndContext,
  closestCenter,
  KeyboardSensor,
  PointerSensor,
  useSensor,
  useSensors,
} from '@dnd-kit/core'
import {
  arrayMove,
  SortableContext,
  sortableKeyboardCoordinates,
  useSortable,
  verticalListSortingStrategy,
} from '@dnd-kit/sortable'
import { CSS } from '@dnd-kit/utilities'

import api from '../../utils/api'
import { useApi } from '../../hooks/useApi'
import { useToast } from '../../hooks/useToast'
import { ToastContainer, Button, Modal, FormGroup, Toggle, ConfirmDialog, DragHandle, EmptyState, Spinner } from '../../components/common'
import { useBuilderSticky } from '../../components/BuilderMobileUi'

/* ── Constants ──────────────────────────────────────────────────────── */
const FIELD_TYPES = [
  { value: 'text',     label: 'Text Input',        icon: '✏️' },
  { value: 'textarea', label: 'Text Area',          icon: '📝' },
  { value: 'select',   label: 'Dropdown',           icon: '📋' },
  { value: 'radio',    label: 'Radio Buttons',      icon: '⭕' },
  { value: 'checkbox', label: 'Checkbox',           icon: '☑️' },
  { value: 'number',   label: 'Number',             icon: '🔢' },
  { value: 'date',     label: 'Date Picker',        icon: '📅' },
  { value: 'email',    label: 'Email',              icon: '✉️' },
  { value: 'phone',    label: 'Phone',              icon: '📞' },
  { value: 'file',     label: 'File Upload',        icon: '📎' },
  { value: 'heading',  label: 'Section Heading',    icon: '📌' },
  { value: 'info',     label: 'Info Text',          icon: 'ℹ️' },
]

const CONDITION_OPERATORS = [
  { value: 'equals',        label: 'equals' },
  { value: 'not_equals',    label: 'does not equal' },
  { value: 'contains',      label: 'contains' },
  { value: 'not_contains',  label: 'does not contain' },
  { value: 'is_empty',      label: 'is empty' },
  { value: 'is_not_empty',  label: 'is not empty' },
]

const STEP_LABELS = {
  1: 'Step 1 — Service Details',
  2: 'Step 2 — Applicant Info',
  3: 'Step 3 — Contact & Location',
  98: 'Step 98 — Contact Details',
  99: 'Step 99 — File Uploads',
}

/* ── Empty field template ───────────────────────────────────────────── */
function emptyField(step = 1) {
  return {
    id: null,
    field_key: '',
    label: '',
    field_type: 'text',
    step,
    required: false,
    placeholder: '',
    help_text: '',
    options: [],
    conditions: null,
    is_active: true,
    sort_order: 9999,
    validation_rules: {},
  }
}

/* ── Main Form Builder Page ─────────────────────────────────────────── */
export default function FormBuilderPage() {
  // Support deep-link from admin portal: /s2nri-builder?page=form-builder&service=5
  const urlServiceId = (() => {
    const params = new URLSearchParams(window.location.search)
    const v = params.get('service')
    return v ? parseInt(v, 10) : null
  })()

  const [selectedServiceId, setSelectedServiceId] = useState(urlServiceId)
  const { data: svcsData, loading: svcsLoading } = useApi('admin/services', { per_page: 200 })

  const services = svcsData?.services || []

  return (
    <div>
      <div className="page-header">
        <div>
          <h1>Form Builder</h1>
          <p>Design and manage service application forms with conditional logic, multi-step flows, and validation rules.</p>
        </div>
      </div>

      {svcsLoading ? (
        <Spinner />
      ) : (
        <div className="s2builder-form-grid" style={{ display: 'grid', gridTemplateColumns: '280px 1fr', gap: 20, alignItems: 'flex-start' }}>
          {/* Service list sidebar */}
          <div className="card">
            <div className="card-header">
              <h3 className="card-title">Services</h3>
              <span style={{ fontSize: 12, color: 'var(--text-muted)' }}>{services.length}</span>
            </div>
            <div style={{ maxHeight: 600, overflowY: 'auto' }}>
              {services.length === 0 ? (
                <EmptyState icon="📋" title="No services" description="Add services first" />
              ) : (
                services.map((svc) => (
                  <button
                    key={svc.id}
                    onClick={() => setSelectedServiceId(svc.id)}
                    style={{
                      width: '100%',
                      padding: '10px 16px',
                      border: 'none',
                      borderBottom: '1px solid var(--border)',
                      background: selectedServiceId === svc.id ? 'var(--brand-light)' : 'transparent',
                      color: selectedServiceId === svc.id ? 'var(--brand)' : 'var(--text)',
                      fontWeight: selectedServiceId === svc.id ? 700 : 400,
                      cursor: 'pointer',
                      textAlign: 'left',
                      fontSize: 13,
                      display: 'flex',
                      alignItems: 'center',
                      gap: 8,
                    }}
                  >
                    <span>{svc.icon || '📄'}</span>
                    <span style={{ flex: 1 }}>{svc.name}</span>
                    {selectedServiceId === svc.id && <span>›</span>}
                  </button>
                ))
              )}
            </div>
          </div>

          {/* Form editor */}
          {selectedServiceId ? (
            <FormEditor serviceId={selectedServiceId} serviceName={services.find(s => s.id === selectedServiceId)?.name} />
          ) : (
            <div className="card">
              <EmptyState
                icon="👈"
                title="Select a service"
                description="Choose a service from the left to edit its form fields"
              />
            </div>
          )}
        </div>
      )}
    </div>
  )
}

/* ── Form Editor ────────────────────────────────────────────────────── */
function FormEditor({ serviceId, serviceName }) {
  const [activeStep, setActiveStep] = useState(1)
  const [editingField, setEditingField] = useState(null)
  const [confirmDelete, setConfirmDelete] = useState(null)
  const [saving, setSaving] = useState(false)
  const { toasts, toast } = useToast()

  const { data, loading, error, reload, setData } = useApi(`admin/services/${serviceId}/form-fields`)
  const fields = data?.fields || []

  const sensors = useSensors(
    useSensor(PointerSensor),
    useSensor(KeyboardSensor, { coordinateGetter: sortableKeyboardCoordinates })
  )

  // Fields for current step
  const stepFields = fields.filter(f => Number(f.step) === activeStep)

  // Steps that have fields
  const usedSteps = [...new Set(fields.map(f => Number(f.step)))].sort((a, b) => a - b)
  const allSteps = [...new Set([1, 2, 3, ...usedSteps])].sort((a, b) => a - b)

  async function handleDragEnd({ active, over }) {
    if (!over || active.id === over.id) return

    const oldIndex = stepFields.findIndex(f => String(f.id) === String(active.id))
    const newIndex = stepFields.findIndex(f => String(f.id) === String(over.id))

    const reordered = arrayMove(stepFields, oldIndex, newIndex).map((f, i) => ({ ...f, sort_order: i + 1 }))
    const otherFields = fields.filter(f => Number(f.step) !== activeStep)
    const newFields = [...otherFields, ...reordered].sort((a, b) => Number(a.step) - Number(b.step) || Number(a.sort_order) - Number(b.sort_order))
    setData({ ...data, fields: newFields })

    try {
      await api.put(`admin/services/${serviceId}/form-fields/reorder`, {
        order: reordered.map((f, i) => ({ id: f.id, sort_order: i + 1, step: activeStep }))
      })
    } catch (err) {
      toast.error('Failed to save order')
      reload()
    }
  }

  async function saveField(fieldData) {
    setSaving(true)
    try {
      if (fieldData.id) {
        await api.put(`admin/services/${serviceId}/form-fields/${fieldData.id}`, fieldData)
        toast.success('Field updated')
      } else {
        await api.post(`admin/services/${serviceId}/form-fields`, fieldData)
        toast.success('Field created')
      }
      setEditingField(null)
      reload()
    } catch (err) {
      toast.error(err.message || 'Save failed')
    } finally {
      setSaving(false)
    }
  }

  async function deleteField(id) {
    try {
      await api.delete(`admin/services/${serviceId}/form-fields/${id}`)
      toast.success('Field deleted')
      setConfirmDelete(null)
      reload()
    } catch (err) {
      toast.error(err.message)
    }
  }

  async function toggleField(field) {
    try {
      await api.patch(`admin/services/${serviceId}/form-fields/${field.id}/toggle`, {})
      reload()
    } catch (err) {
      toast.error(err.message)
    }
  }

  const addFieldSticky = useMemo(
    () => (
      <Button
        variant="primary"
        className="btn-lg s2-builder-sticky-primary"
        style={{ width: '100%', justifyContent: 'center' }}
        icon="+"
        onClick={() => setEditingField({ ...emptyField(activeStep) })}
      >
        Add Field
      </Button>
    ),
    [activeStep],
  )
  useBuilderSticky(addFieldSticky)

  if (loading) return <div className="card"><Spinner /></div>
  if (error) return <div className="card card-body"><div className="alert alert-error">{error}</div></div>

  return (
    <>
      <ToastContainer toasts={toasts} />

      <div className="card">
        <div className="card-header">
          <div>
            <h3 className="card-title">Form Editor — {serviceName}</h3>
            <p style={{ margin: 0, fontSize: 12, color: 'var(--text-muted)' }}>
              {fields.length} field{fields.length !== 1 ? 's' : ''} • Drag to reorder within each step
            </p>
          </div>
          <div className="s2-builder-header-save--desktop-only" style={{ display: 'flex', gap: 8 }}>
            <Button
              variant="primary"
              size="sm"
              icon="+"
              onClick={() => setEditingField({ ...emptyField(activeStep) })}
            >
              Add Field
            </Button>
          </div>
        </div>

        {/* Step tabs */}
        <div style={{ padding: '0 20px', borderBottom: '1px solid var(--border)', display: 'flex', gap: 4, overflowX: 'auto' }}>
          {allSteps.map(step => {
            const count = fields.filter(f => Number(f.step) === step).length
            return (
              <button
                key={step}
                onClick={() => setActiveStep(step)}
                className={`tab-btn ${activeStep === step ? 'active' : ''}`}
                style={{ flexShrink: 0 }}
              >
                {STEP_LABELS[step] || `Step ${step}`}
                {count > 0 && (
                  <span style={{
                    background: activeStep === step ? 'var(--brand)' : 'var(--surface-3)',
                    color: activeStep === step ? '#fff' : 'var(--text-muted)',
                    padding: '1px 7px',
                    borderRadius: 99,
                    fontSize: 11,
                    fontWeight: 700,
                  }}>{count}</span>
                )}
              </button>
            )
          })}
          <button
            onClick={() => {
              const newStep = Math.max(...allSteps) + 1
              setActiveStep(newStep)
              setEditingField({ ...emptyField(newStep) })
            }}
            className="tab-btn"
            style={{ flexShrink: 0 }}
          >
            + Step
          </button>
        </div>

        {/* Field list */}
        <div className="card-body">
          {stepFields.length === 0 ? (
            <EmptyState
              icon="📝"
              title="No fields in this step"
              description="Add your first field using the button above"
              action={
                <Button variant="primary" onClick={() => setEditingField({ ...emptyField(activeStep) })}>
                  Add Field to Step {activeStep}
                </Button>
              }
            />
          ) : (
            <DndContext sensors={sensors} collisionDetection={closestCenter} onDragEnd={handleDragEnd}>
              <SortableContext items={stepFields.map(f => String(f.id))} strategy={verticalListSortingStrategy}>
                <div style={{ display: 'flex', flexDirection: 'column', gap: 8 }}>
                  {stepFields.map(field => (
                    <SortableField
                      key={field.id}
                      field={field}
                      allFields={fields}
                      onEdit={() => setEditingField({ ...field })}
                      onDelete={() => setConfirmDelete(field)}
                      onToggle={() => toggleField(field)}
                    />
                  ))}
                </div>
              </SortableContext>
            </DndContext>
          )}
        </div>
      </div>

      {/* Field editor modal */}
      {editingField && (
        <FieldEditorModal
          field={editingField}
          allFields={fields}
          saving={saving}
          onSave={saveField}
          onClose={() => setEditingField(null)}
        />
      )}

      {/* Confirm delete */}
      <ConfirmDialog
        open={!!confirmDelete}
        onCancel={() => setConfirmDelete(null)}
        onConfirm={() => deleteField(confirmDelete?.id)}
        title="Delete Field"
        message={`Delete "${confirmDelete?.label}"? This cannot be undone.`}
        confirmLabel="Delete Field"
        danger
      />
    </>
  )
}

/* ── Sortable Field Row ──────────────────────────────────────────────── */
function SortableField({ field, allFields, onEdit, onDelete, onToggle }) {
  const {
    attributes,
    listeners,
    setNodeRef,
    transform,
    transition,
    isDragging,
  } = useSortable({ id: String(field.id) })

  const style = {
    transform: CSS.Transform.toString(transform),
    transition,
    opacity: isDragging ? 0.4 : 1,
  }

  const typeInfo = FIELD_TYPES.find(t => t.value === field.field_type)

  return (
    <div
      ref={setNodeRef}
      style={{
        ...style,
        display: 'flex',
        alignItems: 'center',
        gap: 10,
        padding: '10px 12px',
        background: field.is_active ? 'var(--surface)' : 'var(--surface-3)',
        border: '1px solid var(--border)',
        borderRadius: 8,
        opacity: field.is_active ? 1 : 0.6,
      }}
    >
      <DragHandle {...attributes} {...listeners} />

      <span style={{ fontSize: 18, width: 24, textAlign: 'center' }}>{typeInfo?.icon || '📄'}</span>

      <div style={{ flex: 1, minWidth: 0 }}>
        <div style={{ fontWeight: 600, fontSize: 13 }}>
          {field.label || <em style={{ color: 'var(--text-muted)' }}>Unlabelled field</em>}
          {field.required && <span style={{ color: 'var(--red)', marginLeft: 4 }}>*</span>}
        </div>
        <div style={{ fontSize: 11, color: 'var(--text-muted)', display: 'flex', gap: 8, marginTop: 2 }}>
          <span>{typeInfo?.label || field.field_type}</span>
          {field.field_key && <span>key: {field.field_key}</span>}
          {field.conditions && <span style={{ color: 'var(--amber)' }}>⚡ conditional</span>}
        </div>
      </div>

      {/* Condition indicator */}
      {field.conditions?.rules?.length > 0 && (
        <span title="Has conditional logic" style={{ fontSize: 14 }}>⚡</span>
      )}

      {/* Options count */}
      {field.options?.length > 0 && (
        <span style={{ fontSize: 11, color: 'var(--text-muted)', background: 'var(--surface-3)', padding: '2px 7px', borderRadius: 4 }}>
          {field.options.length} options
        </span>
      )}

      <Toggle checked={!!field.is_active} onChange={onToggle} />

      <Button variant="secondary" size="sm" onClick={onEdit}>Edit</Button>
      <Button variant="ghost" size="sm" onClick={onDelete} style={{ color: 'var(--red)' }}>✕</Button>
    </div>
  )
}

/* ── Field Editor Modal ─────────────────────────────────────────────── */
function FieldEditorModal({ field, allFields, saving, onSave, onClose }) {
  const [form, setForm] = useState(field)
  const [tab, setTab] = useState('basic')
  const [newOption, setNewOption] = useState('')

  const set = (key, val) => setForm(f => ({ ...f, [key]: val }))
  const setValidation = (key, val) => setForm(f => ({
    ...f,
    validation_rules: { ...f.validation_rules, [key]: val }
  }))

  function autoKey() {
    if (!form.field_key && form.label) {
      set('field_key', form.label.toLowerCase().replace(/[^a-z0-9]+/g, '_').replace(/^_+|_+$/g, ''))
    }
  }

  function addOption() {
    if (!newOption.trim()) return
    set('options', [...(form.options || []), newOption.trim()])
    setNewOption('')
  }

  function removeOption(i) {
    set('options', form.options.filter((_, idx) => idx !== i))
  }

  const hasOptions = ['select', 'radio', 'checkbox'].includes(form.field_type)
  const otherFields = allFields.filter(f => f.id !== form.id && f.step <= form.step && !['heading', 'info'].includes(f.field_type))

  return (
    <Modal
      open
      onClose={onClose}
      title={form.id ? `Edit — ${form.label}` : 'New Field'}
      size="lg"
      footer={
        <>
          <Button variant="secondary" onClick={onClose}>Cancel</Button>
          <Button variant="primary" loading={saving} onClick={() => onSave(form)}>
            {form.id ? 'Update Field' : 'Create Field'}
          </Button>
        </>
      }
    >
      {/* Tabs */}
      <div className="tabs" style={{ marginBottom: 20 }}>
        {[['basic', '📋 Basic'], ['options', '📊 Options'], ['validation', '✓ Validation'], ['conditions', '⚡ Conditions']].map(([key, label]) => (
          <button key={key} className={`tab-btn ${tab === key ? 'active' : ''}`} onClick={() => setTab(key)}>
            {label}
          </button>
        ))}
      </div>

      {/* Basic tab */}
      {tab === 'basic' && (
        <div>
          <div className="form-row cols-2">
            <FormGroup label="Field Type" required>
              <select
                className="form-select"
                value={form.field_type}
                onChange={e => set('field_type', e.target.value)}
              >
                {FIELD_TYPES.map(t => (
                  <option key={t.value} value={t.value}>{t.icon} {t.label}</option>
                ))}
              </select>
            </FormGroup>
            <FormGroup label="Form Step">
              <select className="form-select" value={form.step} onChange={e => set('step', Number(e.target.value))}>
                {[1, 2, 3, 98, 99].map(s => (
                  <option key={s} value={s}>{STEP_LABELS[s] || `Step ${s}`}</option>
                ))}
              </select>
            </FormGroup>
          </div>

          <FormGroup label="Label" required>
            <input
              className="form-input"
              value={form.label}
              onChange={e => set('label', e.target.value)}
              onBlur={autoKey}
              placeholder="e.g. Document Type"
            />
          </FormGroup>

          <FormGroup label="Field Key" hint="Unique identifier used in form data. Auto-generated from label.">
            <input
              className="form-input"
              value={form.field_key}
              onChange={e => set('field_key', e.target.value.toLowerCase().replace(/[^a-z0-9_]/g, ''))}
              placeholder="e.g. document_type"
            />
          </FormGroup>

          {!['heading', 'info'].includes(form.field_type) && (
            <FormGroup label="Placeholder">
              <input
                className="form-input"
                value={form.placeholder || ''}
                onChange={e => set('placeholder', e.target.value)}
                placeholder="Placeholder text shown inside the field"
              />
            </FormGroup>
          )}

          <FormGroup label="Help Text">
            <textarea
              className="form-textarea"
              value={form.help_text || ''}
              onChange={e => set('help_text', e.target.value)}
              placeholder="Optional helper text shown below the field"
              rows={2}
            />
          </FormGroup>

          <div style={{ display: 'flex', gap: 20 }}>
            <Toggle checked={!!form.required} onChange={v => set('required', v)} label="Required field" />
            <Toggle checked={!!form.is_active} onChange={v => set('is_active', v)} label="Active" />
          </div>
        </div>
      )}

      {/* Options tab */}
      {tab === 'options' && (
        <div>
          {!hasOptions ? (
            <div className="alert alert-info">
              Options are only available for Dropdown, Radio, and Checkbox field types.
              Current type: <strong>{FIELD_TYPES.find(t => t.value === form.field_type)?.label}</strong>
            </div>
          ) : (
            <>
              <p style={{ fontSize: 13, color: 'var(--text-muted)', marginTop: 0 }}>
                Add the selectable options for this field. Drag to reorder.
              </p>

              {(form.options || []).map((opt, i) => (
                <div key={i} style={{ display: 'flex', gap: 8, marginBottom: 6 }}>
                  <input
                    className="form-input"
                    value={opt}
                    onChange={e => {
                      const opts = [...form.options]
                      opts[i] = e.target.value
                      set('options', opts)
                    }}
                  />
                  <Button variant="ghost" size="sm" onClick={() => removeOption(i)} style={{ color: 'var(--red)', flexShrink: 0 }}>✕</Button>
                </div>
              ))}

              <div style={{ display: 'flex', gap: 8, marginTop: 8 }}>
                <input
                  className="form-input"
                  value={newOption}
                  onChange={e => setNewOption(e.target.value)}
                  placeholder="Type an option and press Add"
                  onKeyDown={e => e.key === 'Enter' && addOption()}
                />
                <Button variant="secondary" size="sm" onClick={addOption} style={{ flexShrink: 0 }}>Add</Button>
              </div>

              <div style={{ marginTop: 12, padding: 12, background: 'var(--surface-2)', borderRadius: 8, fontSize: 12, color: 'var(--text-muted)' }}>
                💡 Tip: You can also import options from the Dropdown Data manager (Admin → Settings → Dropdown Data) for dynamic lists.
              </div>
            </>
          )}
        </div>
      )}

      {/* Validation tab */}
      {tab === 'validation' && (
        <div>
          <p style={{ fontSize: 13, color: 'var(--text-muted)', marginTop: 0 }}>
            Configure validation rules. These are enforced when the form is submitted.
          </p>

          {form.field_type === 'text' && (
            <div className="form-row cols-2">
              <FormGroup label="Min Length">
                <input type="number" className="form-input" value={form.validation_rules?.min_length || ''} onChange={e => setValidation('min_length', Number(e.target.value))} min={0} />
              </FormGroup>
              <FormGroup label="Max Length">
                <input type="number" className="form-input" value={form.validation_rules?.max_length || ''} onChange={e => setValidation('max_length', Number(e.target.value))} min={0} />
              </FormGroup>
            </div>
          )}

          {form.field_type === 'number' && (
            <div className="form-row cols-2">
              <FormGroup label="Min Value">
                <input type="number" className="form-input" value={form.validation_rules?.min || ''} onChange={e => setValidation('min', Number(e.target.value))} />
              </FormGroup>
              <FormGroup label="Max Value">
                <input type="number" className="form-input" value={form.validation_rules?.max || ''} onChange={e => setValidation('max', Number(e.target.value))} />
              </FormGroup>
            </div>
          )}

          {form.field_type === 'file' && (
            <>
              <FormGroup label="Allowed File Types" hint="Comma-separated: pdf,jpg,png">
                <input
                  className="form-input"
                  value={form.validation_rules?.allowed_types || ''}
                  onChange={e => setValidation('allowed_types', e.target.value)}
                  placeholder="pdf,jpg,jpeg,png,doc,docx"
                />
              </FormGroup>
              <FormGroup label="Max File Size (MB)">
                <input type="number" className="form-input" value={form.validation_rules?.max_size_mb || ''} onChange={e => setValidation('max_size_mb', Number(e.target.value))} min={1} max={100} />
              </FormGroup>
            </>
          )}

          <FormGroup label="Custom Error Message" hint="Shown when validation fails. Leave blank for default message.">
            <input
              className="form-input"
              value={form.validation_rules?.error_message || ''}
              onChange={e => setValidation('error_message', e.target.value)}
              placeholder="e.g. Please enter a valid passport number"
            />
          </FormGroup>

          <FormGroup label="Regex Pattern" hint="Advanced: validate with a regex. Leave blank to skip.">
            <input
              className="form-input"
              value={form.validation_rules?.pattern || ''}
              onChange={e => setValidation('pattern', e.target.value)}
              placeholder="e.g. ^[A-Z]{1}[0-9]{7}$"
              style={{ fontFamily: 'monospace' }}
            />
          </FormGroup>
        </div>
      )}

      {/* Conditions tab */}
      {tab === 'conditions' && (
        <ConditionsEditor
          conditions={form.conditions}
          onChange={v => set('conditions', v)}
          fields={otherFields}
        />
      )}
    </Modal>
  )
}

/* ── Conditions Editor ──────────────────────────────────────────────── */
function ConditionsEditor({ conditions, onChange, fields }) {
  const cond = conditions || { action: 'show', match: 'all', rules: [] }

  function addRule() {
    onChange({
      ...cond,
      rules: [...cond.rules, { field_key: '', operator: 'equals', value: '' }]
    })
  }

  function updateRule(i, key, val) {
    const rules = [...cond.rules]
    rules[i] = { ...rules[i], [key]: val }
    onChange({ ...cond, rules })
  }

  function removeRule(i) {
    const rules = cond.rules.filter((_, idx) => idx !== i)
    onChange(rules.length === 0 ? null : { ...cond, rules })
  }

  return (
    <div>
      <p style={{ fontSize: 13, color: 'var(--text-muted)', marginTop: 0 }}>
        Conditional logic controls when this field is shown or hidden based on other fields' values.
      </p>

      {fields.length === 0 && (
        <div className="alert alert-info">
          No other fields available for conditions. Add more fields to the form first.
        </div>
      )}

      {fields.length > 0 && (
        <>
          <div style={{ display: 'flex', gap: 12, alignItems: 'center', marginBottom: 16, flexWrap: 'wrap' }}>
            <div style={{ display: 'flex', gap: 8, alignItems: 'center' }}>
              <strong style={{ fontSize: 13 }}>When</strong>
              <select
                className="form-select"
                value={cond.match}
                onChange={e => onChange({ ...cond, match: e.target.value })}
                style={{ width: 'auto' }}
              >
                <option value="all">ALL</option>
                <option value="any">ANY</option>
              </select>
              <strong style={{ fontSize: 13 }}>of the following match, then</strong>
              <select
                className="form-select"
                value={cond.action}
                onChange={e => onChange({ ...cond, action: e.target.value })}
                style={{ width: 'auto' }}
              >
                <option value="show">Show</option>
                <option value="hide">Hide</option>
                <option value="require">Require</option>
              </select>
              <strong style={{ fontSize: 13 }}>this field</strong>
            </div>
          </div>

          {(cond.rules || []).map((rule, i) => (
            <div key={i} style={{ display: 'flex', gap: 8, marginBottom: 8, alignItems: 'center', flexWrap: 'wrap' }}>
              <select
                className="form-select"
                value={rule.field_key}
                onChange={e => updateRule(i, 'field_key', e.target.value)}
                style={{ flex: 2, minWidth: 140 }}
              >
                <option value="">— Select field —</option>
                {fields.map(f => (
                  <option key={f.id} value={f.field_key}>{f.label}</option>
                ))}
              </select>

              <select
                className="form-select"
                value={rule.operator}
                onChange={e => updateRule(i, 'operator', e.target.value)}
                style={{ flex: 2, minWidth: 140 }}
              >
                {CONDITION_OPERATORS.map(op => (
                  <option key={op.value} value={op.value}>{op.operator} {op.label}</option>
                ))}
              </select>

              {!['is_empty', 'is_not_empty'].includes(rule.operator) && (
                <input
                  className="form-input"
                  value={rule.value || ''}
                  onChange={e => updateRule(i, 'value', e.target.value)}
                  placeholder="Value"
                  style={{ flex: 2, minWidth: 100 }}
                />
              )}

              <Button variant="ghost" size="sm" onClick={() => removeRule(i)} style={{ color: 'var(--red)', flexShrink: 0 }}>✕</Button>
            </div>
          ))}

          <Button variant="secondary" size="sm" onClick={addRule}>+ Add Condition</Button>

          {cond.rules?.length === 0 && (
            <p style={{ fontSize: 12, color: 'var(--text-muted)', marginTop: 12 }}>
              No conditions yet. This field will always be shown.
            </p>
          )}
        </>
      )}
    </div>
  )
}
