import { useState, useEffect, useCallback } from 'react';
import { useParams, useNavigate } from 'react-router-dom';
import { Screen } from '../components/Screen';
import { Button } from '../components/Button';
import { Input } from '../components/Input';
import { Waybill, WaybillLine, WaybillDivider } from '../components/Waybill';
import { Skeleton, SkeletonRowList } from '../components/Skeleton';
import {
  getAccountSummary,
  listEmployees,
  inviteEmployee,
  removeEmployee,
  updateEmployeeCap,
  getMyAccounts,
  getAccountBookings,
  getSpendAnalytics,
  getSpendByEmployee,
  ApiError, getErrorMessage,
  type AccountSummary,
  type Employee,
  type AccountBooking,
  type SpendAnalyticsRow,
  type SpendByEmployeeRow,
} from '../api';

function money(v: string): string {
  return `₹${parseFloat(v).toLocaleString('en-IN', { minimumFractionDigits: 2, maximumFractionDigits: 2 })}`;
}

export function AccountDashboardPage() {
  const { accountId } = useParams<{ accountId: string }>();
  const navigate = useNavigate();
  const [summary, setSummary] = useState<AccountSummary | null>(null);
  const [employees, setEmployees] = useState<Employee[] | null>(null);
  const [bookings, setBookings] = useState<AccountBooking[] | null>(null);
  const [spendAnalytics, setSpendAnalytics] = useState<SpendAnalyticsRow[] | null>(null);
  const [employeeSpend, setEmployeeSpend] = useState<SpendByEmployeeRow[] | null>(null);
  const [isAdmin, setIsAdmin] = useState(false);
  const [error, setError] = useState('');
  const [showInvite, setShowInvite] = useState(false);
  const [inviteEmail, setInviteEmail] = useState('');
  const [inviteRole, setInviteRole] = useState<'employee' | 'account_admin'>('employee');
  const [inviting, setInviting] = useState(false);
  const [editingCapFor, setEditingCapFor] = useState<string | null>(null);
  const [capInput, setCapInput] = useState('');
  const [savingCap, setSavingCap] = useState(false);

  const refresh = useCallback(async () => {
    if (!accountId) return;
    try {
      const [s, e, b, analytics, byEmployee, myAccounts] = await Promise.all([
        getAccountSummary(accountId),
        listEmployees(accountId),
        getAccountBookings(accountId),
        getSpendAnalytics(accountId),
        getSpendByEmployee(accountId).catch(() => null),
        getMyAccounts(),
      ]);
      setSummary(s);
      setEmployees(e);
      setBookings(b);
      setSpendAnalytics(analytics);
      setEmployeeSpend(byEmployee?.employees ?? []);
      setIsAdmin(myAccounts.some((a) => a.account_id === accountId && a.role === 'account_admin'));
    } catch (err) {
      if (err instanceof ApiError && err.status === 403) {
        setError("You don't have access to this account.");
      } else {
        setError(getErrorMessage(err, 'Could not load this account.'));
      }
    }
  }, [accountId]);

  useEffect(() => {
    void refresh();
  }, [refresh]);

  async function handleInvite() {
    if (!accountId || !inviteEmail.trim()) return;
    setError('');
    setInviting(true);
    try {
      await inviteEmployee(accountId, inviteEmail.trim(), inviteRole);
      setInviteEmail('');
      setShowInvite(false);
      await refresh();
    } catch (err) {
      setError(getErrorMessage(err, 'Could not send this invite.'));
    } finally {
      setInviting(false);
    }
  }

  async function handleRemove(employeeId: string) {
    if (!accountId) return;
    setError('');
    try {
      await removeEmployee(accountId, employeeId);
      await refresh();
    } catch (err) {
      setError(getErrorMessage(err, 'Could not remove this employee.'));
    }
  }

  async function handleSaveCap(employeeId: string) {
    if (!accountId) return;
    setError('');
    setSavingCap(true);
    try {
      const newCap = capInput.trim() === '' ? null : parseFloat(capInput);
      await updateEmployeeCap(accountId, employeeId, newCap);
      setEditingCapFor(null);
      await refresh();
    } catch (err) {
      setError(getErrorMessage(err, 'Could not update this cap.'));
    } finally {
      setSavingCap(false);
    }
  }

  if (error) {
    return (
      <Screen eyebrow="Corporate Portal" title="Account">
        <p style={{ color: 'var(--danger)' }}>{error}</p>
        <Button variant="ghost" onClick={() => navigate('/accounts')}>
          Back to my accounts
        </Button>
      </Screen>
    );
  }

  if (!summary || !employees || !bookings || !spendAnalytics || employeeSpend === null) {
    return (
      <Screen eyebrow="Corporate Portal" title="Account">
        <div style={{ border: '1px solid var(--border)', borderRadius: 16, background: 'var(--surface)', padding: 24 }}>
          <Skeleton width={120} height={12} style={{ marginBottom: 12 }} />
          <Skeleton width="100%" height={14} style={{ marginBottom: 8 }} />
          <Skeleton width="100%" height={14} style={{ marginBottom: 8 }} />
          <Skeleton width={160} height={22} />
        </div>
        <SkeletonRowList count={2} />
      </Screen>
    );
  }

  return (
    <Screen eyebrow="Corporate Portal" title={summary.name}>
      <Waybill label="Credit summary">
        <WaybillLine label="Credit limit" value={money(summary.credit_limit)} />
        <WaybillLine label="Committed spend" value={money(summary.committed_spend)} />
        <WaybillLine label="Reserved (in-flight)" value={money(summary.reserved_spend)} />
        <WaybillDivider />
        <WaybillLine label="Available credit" value={money(summary.available_credit)} emphasis />
      </Waybill>

      <Button variant="ghost" onClick={() => navigate(`/accounts/${accountId}/invoices`)}>
        🧾 View invoices
      </Button>

      <h2 style={{ fontSize: 15, marginTop: 10 }}>Monthly spend</h2>
      {spendAnalytics.length === 0 ? (
        <p style={{ color: 'var(--text-muted)', fontSize: 14 }}>No completed trips yet.</p>
      ) : (
        <div style={{ display: 'flex', flexDirection: 'column', gap: 8 }}>
          {spendAnalytics.map((row) => (
            <div
              key={row.month}
              style={{
                display: 'flex',
                justifyContent: 'space-between',
                border: '1px solid var(--border)',
                borderRadius: 10,
                padding: '10px 14px',
              }}
            >
              <div>
                <div style={{ fontSize: 13 }}>{new Date(row.month).toLocaleDateString(undefined, { month: 'long', year: 'numeric' })}</div>
                <div style={{ fontSize: 11, color: 'var(--text-muted)' }}>{row.trip_count} trip{row.trip_count === 1 ? '' : 's'}</div>
              </div>
              <div style={{ fontFamily: 'var(--font-mono)', fontWeight: 600 }}>{money(String(row.total_spend))}</div>
            </div>
          ))}
        </div>
      )}

      <h2 style={{ fontSize: 15, marginTop: 10 }}>Spend by employee (this month)</h2>
      {employeeSpend.length === 0 ? (
        <p style={{ color: 'var(--text-muted)', fontSize: 14 }}>No completed trips this month.</p>
      ) : (
        <div style={{ display: 'flex', flexDirection: 'column', gap: 8 }}>
          {employeeSpend.map((row) => (
            <div
              key={row.employee_phone}
              style={{
                display: 'flex',
                justifyContent: 'space-between',
                border: '1px solid var(--border)',
                borderRadius: 10,
                padding: '10px 14px',
              }}
            >
              <div>
                <div style={{ fontSize: 13 }}>{row.employee_name}</div>
                <div style={{ fontSize: 11, color: 'var(--text-muted)' }}>
                  +91 {row.employee_phone} · {row.trip_count} trip{row.trip_count === 1 ? '' : 's'}
                </div>
              </div>
              <div style={{ fontFamily: 'var(--font-mono)', fontWeight: 600 }}>{money(String(row.total_spend))}</div>
            </div>
          ))}
        </div>
      )}

      <h2 style={{ fontSize: 15, marginTop: 10 }}>Recent bookings</h2>
      {bookings.length === 0 ? (
        <p style={{ color: 'var(--text-muted)', fontSize: 14 }}>No bookings billed to this account yet.</p>
      ) : (
        <div style={{ display: 'flex', flexDirection: 'column', gap: 8 }}>
          {bookings.map((b) => (
            <div
              key={b.id}
              style={{
                display: 'flex',
                justifyContent: 'space-between',
                alignItems: 'center',
                border: '1px solid var(--border)',
                borderRadius: 10,
                padding: '10px 14px',
              }}
            >
              <div>
                <div style={{ fontSize: 13, fontFamily: 'var(--font-mono)' }}>+91 {b.employee_phone}</div>
                <div style={{ fontSize: 11, color: 'var(--text-muted)' }}>
                  {b.status} · {new Date(b.created_at).toLocaleDateString()}
                </div>
              </div>
              <div style={{ fontFamily: 'var(--font-mono)', fontSize: 14, fontWeight: 600 }}>
                ₹{b.fare_breakdown.final_fare.toFixed(2)}
              </div>
            </div>
          ))}
        </div>
      )}

      <div style={{ display: 'flex', justifyContent: 'space-between', alignItems: 'center', marginTop: 10 }}>
        <h2 style={{ fontSize: 15 }}>Team</h2>
        {isAdmin && (
          <Button
            variant="ghost"
            style={{ width: 'auto', padding: '4px 14px', minHeight: 32, fontSize: 13 }}
            onClick={() => setShowInvite((v) => !v)}
          >
            {showInvite ? 'Cancel' : '+ Invite'}
          </Button>
        )}
      </div>

      {showInvite && (
        <div style={{ border: '1px solid var(--border)', borderRadius: 12, background: 'var(--surface)', padding: 16 }}>
          <Input label="Email" type="email" value={inviteEmail} onChange={(e) => setInviteEmail(e.target.value)} />
          <div style={{ display: 'flex', gap: 8, marginTop: 10, marginBottom: 10 }}>
            <label style={{ display: 'flex', alignItems: 'center', gap: 6, fontSize: 13 }}>
              <input type="radio" checked={inviteRole === 'employee'} onChange={() => setInviteRole('employee')} />
              Employee
            </label>
            <label style={{ display: 'flex', alignItems: 'center', gap: 6, fontSize: 13 }}>
              <input type="radio" checked={inviteRole === 'account_admin'} onChange={() => setInviteRole('account_admin')} />
              Account admin
            </label>
          </div>
          <Button loading={inviting} onClick={handleInvite}>
            Send invite
          </Button>
        </div>
      )}

      {employees.length === 0 ? (
        <p style={{ color: 'var(--text-muted)', fontSize: 14 }}>No team members yet.</p>
      ) : (
        employees.map((e) => (
          <div
            key={e.id}
            data-employee-email={e.email}
            style={{
              display: 'flex',
              justifyContent: 'space-between',
              alignItems: 'center',
              border: '1px solid var(--border)',
              borderRadius: 10,
              padding: '10px 14px',
              opacity: e.status === 'removed' ? 0.5 : 1,
            }}
          >
            <div>
              <div style={{ fontSize: 14 }}>{e.email}</div>
              <div style={{ fontSize: 11, color: 'var(--text-muted)' }}>
                {e.role.replace(/_/g, ' ')} · {e.status}
                {e.per_user_monthly_cap && ` · cap ₹${parseFloat(e.per_user_monthly_cap).toFixed(0)}/mo`}
              </div>
              {editingCapFor === e.id && (
                <div style={{ display: 'flex', gap: 6, marginTop: 8, alignItems: 'center' }}>
                  <input
                    value={capInput}
                    onChange={(ev) => setCapInput(ev.target.value.replace(/[^0-9.]/g, ''))}
                    placeholder="No cap"
                    style={{
                      width: 90,
                      background: 'var(--bg)',
                      border: '1px solid var(--border)',
                      borderRadius: 6,
                      color: 'var(--text)',
                      padding: '4px 8px',
                      fontSize: 12,
                    }}
                  />
                  <button
                    onClick={() => handleSaveCap(e.id)}
                    disabled={savingCap}
                    style={{ background: 'none', border: 'none', color: 'var(--accent-strong)', cursor: 'pointer', fontSize: 12 }}
                  >
                    Save
                  </button>
                  <button
                    onClick={() => setEditingCapFor(null)}
                    style={{ background: 'none', border: 'none', color: 'var(--text-muted)', cursor: 'pointer', fontSize: 12 }}
                  >
                    Cancel
                  </button>
                </div>
              )}
            </div>
            {isAdmin && e.status === 'active' && editingCapFor !== e.id && (
              <div style={{ display: 'flex', gap: 10 }}>
                <button
                  onClick={() => {
                    setEditingCapFor(e.id);
                    setCapInput(e.per_user_monthly_cap ? String(parseFloat(e.per_user_monthly_cap)) : '');
                  }}
                  style={{ background: 'none', border: 'none', color: 'var(--accent-strong)', cursor: 'pointer', fontSize: 12 }}
                >
                  Edit cap
                </button>
                <button
                  onClick={() => handleRemove(e.id)}
                  style={{ background: 'none', border: 'none', color: 'var(--danger)', cursor: 'pointer', fontSize: 12 }}
                >
                  Remove
                </button>
              </div>
            )}
          </div>
        ))
      )}

      <Button variant="ghost" onClick={() => navigate('/accounts')}>
        Back to my accounts
      </Button>
    </Screen>
  );
}
