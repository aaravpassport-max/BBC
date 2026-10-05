import { useState } from 'react'
import { PROGRESS_STEPS, SPECIAL_STATUSES, ALL_STATUSES, getStatusColor } from '../../utils/statuses'
import { Button, Alert, StatusBadge, Modal, FormGroup } from '../../components/common'
import { BuilderToolbar } from '../../components/BuilderMobileUi'
import { useToast } from '../../hooks/useToast'
import { ToastContainer } from '../../components/common'
import api from '../../utils/api'
import { useApi } from '../../hooks/useApi'

export default function StatusManagerPage() {
  const [activeTab, setActiveTab] = useState('workflow')
  const { toasts, toast } = useToast()

  return (
    <>
      <ToastContainer toasts={toasts} />
      <div className="page-header">
        <div>
          <h1>Status Workflow Manager</h1>
          <p>Configure and preview the booking status workflow. All statuses are synchronized across the customer dashboard, admin panel, and public website.</p>
        </div>
      </div>

      <BuilderToolbar className="s2builder-status-tabs">
        {[['workflow', '⚡ Workflow'], ['preview', '👁️ Preview'], ['bulk', '🔄 Bulk Update'], ['audit', '📋 Recent Changes']].map(([key, label]) => (
          <button key={key} type="button" className={`tab-btn ${activeTab === key ? 'active' : ''}`} onClick={() => setActiveTab(key)}>{label}</button>
        ))}
      </BuilderToolbar>

      {activeTab === 'workflow' && <WorkflowView />}
      {activeTab === 'preview' && <PreviewView />}
      {activeTab === 'bulk' && <BulkUpdateView toast={toast} />}
      {activeTab === 'audit' && <AuditView />}
    </>
  )
}

/* ── Workflow View ───────────────────────────────────────────────────── */
function WorkflowView() {
  return (
    <div className="card">
      <div className="card-header">
        <h3 className="card-title">Booking Status Workflow</h3>
      </div>
      <div className="card-body">
        <Alert type="info" style={{ marginBottom: 24 }}>
          The status values below are the canonical values stored in the database. The labels shown here match exactly what appears in the Customer Dashboard progress strip, the Admin booking list, and the Update Status dropdown.
        </Alert>

        {/* Progress flow */}
        <h4 style={{ margin: '0 0 16px', fontSize: 14, fontWeight: 700 }}>Main Workflow (in order)</h4>
        <div style={{ position: 'relative', paddingLeft: 32, marginBottom: 32 }}>
          {/* Vertical line */}
          <div style={{ position: 'absolute', left: 11, top: 8, bottom: 8, width: 2, background: 'var(--border)' }} />

          {PROGRESS_STEPS.map((step, i) => (
            <div key={step.value} style={{ display: 'flex', alignItems: 'flex-start', gap: 16, marginBottom: 20, position: 'relative' }}>
              {/* Node */}
              <div style={{
                width: 24, height: 24, borderRadius: '50%',
                background: step.color, color: '#fff',
                display: 'flex', alignItems: 'center', justifyContent: 'center',
                fontSize: 11, fontWeight: 700, flexShrink: 0,
                position: 'absolute', left: -20, top: 4,
              }}>
                {i + 1}
              </div>

              <div style={{ background: 'var(--surface)', border: `1px solid ${step.color}30`, borderLeft: `4px solid ${step.color}`, borderRadius: 8, padding: '12px 16px', flex: 1 }}>
                <div style={{ display: 'flex', alignItems: 'center', gap: 10, flexWrap: 'wrap' }}>
                  <span style={{ fontSize: 20 }}>{step.icon}</span>
                  <div>
                    <div style={{ fontWeight: 700, fontSize: 14 }}>{step.label}</div>
                    <div style={{ fontSize: 12, color: 'var(--text-muted)', marginTop: 2 }}>{step.description}</div>
                  </div>
                  <code style={{ marginLeft: 'auto', fontSize: 11, background: 'var(--surface-3)', padding: '2px 8px', borderRadius: 4, color: 'var(--text-muted)', flexShrink: 0 }}>
                    DB: {step.value}
                  </code>
                </div>
              </div>
            </div>
          ))}
        </div>

        <h4 style={{ margin: '0 0 16px', fontSize: 14, fontWeight: 700 }}>Special / Non-linear Statuses</h4>
        <div style={{ display: 'grid', gridTemplateColumns: 'repeat(auto-fill, minmax(280px, 1fr))', gap: 12 }}>
          {SPECIAL_STATUSES.map(status => (
            <div key={status.value} style={{
              border: `1px solid ${status.color}30`, borderLeft: `4px solid ${status.color}`,
              borderRadius: 8, padding: '12px 16px', background: 'var(--surface)',
            }}>
              <div style={{ display: 'flex', alignItems: 'center', gap: 8, marginBottom: 6 }}>
                <span style={{ fontSize: 18 }}>{status.icon}</span>
                <span style={{ fontWeight: 700 }}>{status.label}</span>
              </div>
              <div style={{ fontSize: 12, color: 'var(--text-muted)', marginBottom: 6 }}>{status.description}</div>
              <code style={{ fontSize: 11, background: 'var(--surface-3)', padding: '2px 8px', borderRadius: 4, color: 'var(--text-muted)' }}>
                DB: {status.value}
              </code>
            </div>
          ))}
        </div>

        {/* Sync confirmation */}
        <div style={{ marginTop: 32, padding: 20, background: '#f0fdf4', border: '1px solid #a7f3d0', borderRadius: 12 }}>
          <h4 style={{ margin: '0 0 12px', color: '#065f46', fontSize: 14 }}>✅ Status Synchronization</h4>
          <div style={{ display: 'grid', gridTemplateColumns: 'repeat(auto-fill, minmax(200px, 1fr))', gap: 8, fontSize: 13 }}>
            {[
              ['Customer Dashboard', 'Progress strip shows these exact labels'],
              ['/admin/ booking list', 'Status column uses these values'],
              ['/s2nri-admin/ requests', 'Update Status dropdown uses these values'],
              ['API responses', 'status field always contains DB value'],
              ['Audit log', 'All status changes are logged with these values'],
            ].map(([place, desc]) => (
              <div key={place} style={{ display: 'flex', gap: 6, alignItems: 'flex-start' }}>
                <span style={{ color: '#059669', fontWeight: 700, flexShrink: 0 }}>✓</span>
                <div>
                  <div style={{ fontWeight: 600 }}>{place}</div>
                  <div style={{ fontSize: 11, color: '#374151' }}>{desc}</div>
                </div>
              </div>
            ))}
          </div>
        </div>
      </div>
    </div>
  )
}

/* ── Preview View ────────────────────────────────────────────────────── */
function PreviewView() {
  const [selectedStatus, setSelectedStatus] = useState('submitted')

  const stepIndex = PROGRESS_STEPS.findIndex(s => s.value === selectedStatus)
  const isSpecial = SPECIAL_STATUSES.some(s => s.value === selectedStatus)

  return (
    <div className="card">
      <div className="card-header">
        <h3 className="card-title">Progress Strip Preview</h3>
      </div>
      <div className="card-body">
        <FormGroup label="Select a status to preview">
          <select className="form-select" value={selectedStatus} onChange={e => setSelectedStatus(e.target.value)}>
            <optgroup label="Main Workflow">
              {PROGRESS_STEPS.map(s => <option key={s.value} value={s.value}>{s.icon} {s.label}</option>)}
            </optgroup>
            <optgroup label="Special Statuses">
              {SPECIAL_STATUSES.map(s => <option key={s.value} value={s.value}>{s.icon} {s.label}</option>)}
            </optgroup>
          </select>
        </FormGroup>

        {/* Progress strip simulation */}
        <div style={{ padding: 20, background: '#fff', border: '1px solid var(--border)', borderRadius: 12, overflowX: 'auto' }}>
          <div style={{ display: 'flex', alignItems: 'center', gap: 0, minWidth: 600 }}>
            {PROGRESS_STEPS.map((step, i) => {
              const isActive = !isSpecial && i <= stepIndex
              const isCurrent = !isSpecial && i === stepIndex
              return (
                <div key={step.value} style={{ display: 'flex', alignItems: 'center', flex: i < PROGRESS_STEPS.length - 1 ? 1 : 0 }}>
                  <div style={{ display: 'flex', flexDirection: 'column', alignItems: 'center', gap: 4 }}>
                    <div style={{
                      width: 36, height: 36, borderRadius: '50%',
                      background: isActive ? step.color : 'var(--border)',
                      color: '#fff', display: 'flex', alignItems: 'center', justifyContent: 'center',
                      fontSize: 16, border: isCurrent ? `3px solid ${step.color}60` : 'none',
                      boxShadow: isCurrent ? `0 0 0 4px ${step.color}20` : 'none',
                      transition: 'all 0.3s',
                    }}>
                      {isActive ? step.icon : <span style={{ fontSize: 12, fontWeight: 700 }}>{i + 1}</span>}
                    </div>
                    <span style={{
                      fontSize: 10, fontWeight: isCurrent ? 800 : 500,
                      color: isActive ? step.color : 'var(--text-muted)',
                      textAlign: 'center', maxWidth: 70, lineHeight: 1.2,
                    }}>
                      {step.label}
                    </span>
                  </div>
                  {i < PROGRESS_STEPS.length - 1 && (
                    <div style={{
                      flex: 1, height: 2, margin: '0 4px', marginBottom: 20,
                      background: isActive && i < stepIndex ? step.color : 'var(--border)',
                      transition: 'background 0.3s',
                    }} />
                  )}
                </div>
              )
            })}
          </div>
        </div>

        {isSpecial && (
          <Alert type="warning" style={{ marginTop: 16 }}>
            <strong>{ALL_STATUSES.find(s => s.value === selectedStatus)?.icon} {ALL_STATUSES.find(s => s.value === selectedStatus)?.label}</strong> is a special status and does not appear in the main progress strip. It is shown as a separate indicator on the booking card.
          </Alert>
        )}

        <div style={{ marginTop: 16, padding: 16, background: 'var(--surface-2)', borderRadius: 8 }}>
          <strong style={{ fontSize: 13 }}>Current status for preview:</strong>
          <div style={{ marginTop: 8 }}>
            <StatusBadge value={selectedStatus} />
          </div>
        </div>
      </div>
    </div>
  )
}

/* ── Bulk Update View ────────────────────────────────────────────────── */
function BulkUpdateView({ toast }) {
  const [fromStatus, setFromStatus] = useState('')
  const [toStatus, setToStatus] = useState('')
  const [preview, setPreview] = useState(null)
  const [loading, setLoading] = useState(false)
  const [applying, setApplying] = useState(false)

  async function fetchPreview() {
    if (!fromStatus) return
    setLoading(true)
    try {
      const res = await api.get('admin/bookings', { status: fromStatus, per_page: 5 })
      setPreview({ count: res.total, sample: res.rows || [] })
    } catch (err) { toast.error(err.message) }
    finally { setLoading(false) }
  }

  async function applyBulk() {
    if (!fromStatus || !toStatus || !preview) return
    setApplying(true)
    try {
      await api.post('admin/bookings/bulk-status', { from_status: fromStatus, to_status: toStatus })
      toast.success(`Updated ${preview.count} bookings from "${fromStatus}" to "${toStatus}"`)
      setPreview(null)
      setFromStatus('')
      setToStatus('')
    } catch (err) { toast.error(err.message) }
    finally { setApplying(false) }
  }

  return (
    <div className="card">
      <div className="card-header">
        <h3 className="card-title">Bulk Status Update</h3>
      </div>
      <div className="card-body">
        <Alert type="warning" style={{ marginBottom: 20 }}>
          This will update ALL bookings with the selected "From" status to the new status. Use with caution.
        </Alert>

        <div className="form-row cols-2">
          <FormGroup label="From Status (current)">
            <select className="form-select" value={fromStatus} onChange={e => { setFromStatus(e.target.value); setPreview(null) }}>
              <option value="">— Select current status —</option>
              {ALL_STATUSES.map(s => <option key={s.value} value={s.value}>{s.icon} {s.label}</option>)}
            </select>
          </FormGroup>
          <FormGroup label="To Status (new)">
            <select className="form-select" value={toStatus} onChange={e => setToStatus(e.target.value)}>
              <option value="">— Select new status —</option>
              {ALL_STATUSES.map(s => <option key={s.value} value={s.value}>{s.icon} {s.label}</option>)}
            </select>
          </FormGroup>
        </div>

        <div style={{ display: 'flex', gap: 8, marginBottom: 20 }}>
          <Button variant="secondary" onClick={fetchPreview} loading={loading} disabled={!fromStatus}>
            Preview Affected Bookings
          </Button>
          {preview && toStatus && (
            <Button variant="danger" onClick={applyBulk} loading={applying}>
              Update {preview.count} Booking{preview.count !== 1 ? 's' : ''}
            </Button>
          )}
        </div>

        {preview && (
          <div>
            <div style={{ marginBottom: 12, fontWeight: 600, fontSize: 13 }}>
              {preview.count} booking{preview.count !== 1 ? 's' : ''} will be updated
            </div>
            {preview.sample.length > 0 && (
              <table className="data-table">
                <thead>
                  <tr>
                    <th>Reference</th>
                    <th>Customer</th>
                    <th>Service</th>
                    <th>Current Status</th>
                  </tr>
                </thead>
                <tbody>
                  {preview.sample.map(b => (
                    <tr key={b.id}>
                      <td><code>{b.booking_ref}</code></td>
                      <td>{b.customer_name}</td>
                      <td>{b.service_name}</td>
                      <td><StatusBadge value={b.status} /></td>
                    </tr>
                  ))}
                </tbody>
              </table>
            )}
            {preview.count > 5 && (
              <p style={{ fontSize: 12, color: 'var(--text-muted)', marginTop: 8 }}>… and {preview.count - 5} more</p>
            )}
          </div>
        )}
      </div>
    </div>
  )
}

/* ── Audit View ──────────────────────────────────────────────────────── */
function AuditView() {
  const { data, loading } = useApi('admin/audit-log', { action: 'status_changed', per_page: 50 })
  const rows = data?.rows || []

  return (
    <div className="card">
      <div className="card-header">
        <h3 className="card-title">Recent Status Changes</h3>
      </div>
      <div className="card-body">
        {loading ? <div className="loading-center"><div className="spinner" /></div> : (
          rows.length === 0 ? (
            <p style={{ color: 'var(--text-muted)', textAlign: 'center', padding: 40 }}>No status changes recorded yet</p>
          ) : (
            <table className="data-table">
              <thead>
                <tr>
                  <th>Time</th>
                  <th>Booking</th>
                  <th>Changed By</th>
                  <th>From</th>
                  <th>To</th>
                </tr>
              </thead>
              <tbody>
                {rows.map(row => (
                  <tr key={row.id}>
                    <td style={{ fontSize: 11, color: 'var(--text-muted)' }}>{row.created_at}</td>
                    <td><code>{row.entity_id}</code></td>
                    <td>{row.actor_name || row.actor_id}</td>
                    <td>{row.old_value ? <StatusBadge value={row.old_value} /> : '—'}</td>
                    <td>{row.new_value ? <StatusBadge value={row.new_value} /> : '—'}</td>
                  </tr>
                ))}
              </tbody>
            </table>
          )
        )}
      </div>
    </div>
  )
}
