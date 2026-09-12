import { useState, useEffect, useCallback } from 'react';
import { Layout } from '../components/Layout';
import { Button } from '../components/Button';
import { Input } from '../components/Input';
import { AccessDenied } from '../components/AccessDenied';
import {
  listRoles,
  listPermissions,
  getRolePermissions,
  createRole,
  setRolePermissions,
  lookupUserByPhone,
  getUserRoles,
  assignRoleToUser,
  revokeRoleFromUser,
  ApiError, getErrorMessage,
  type Role,
  type Permission,
} from '../api';
import { SkeletonRowList } from '../components/Skeleton';

export function RbacPage() {
  const [roles, setRoles] = useState<Role[] | null>(null);
  const [permissions, setPermissions] = useState<Permission[]>([]);
  const [forbidden, setForbidden] = useState(false);
  const [error, setError] = useState('');
  const [selectedRoleId, setSelectedRoleId] = useState<string | null>(null);
  const [rolePermIds, setRolePermIds] = useState<Set<string>>(new Set());
  const [savingPerms, setSavingPerms] = useState(false);
  const [newRoleName, setNewRoleName] = useState('');
  const [showNewRole, setShowNewRole] = useState(false);

  const [assignPhone, setAssignPhone] = useState('');
  const [foundUser, setFoundUser] = useState<{ id: string; phone: string; account_type: string } | null>(null);
  const [userRoles, setUserRoles] = useState<Role[]>([]);
  const [assignRoleId, setAssignRoleId] = useState('');
  const [searching, setSearching] = useState(false);

  const refresh = useCallback(async () => {
    try {
      const [r, p] = await Promise.all([listRoles(), listPermissions()]);
      setRoles(r);
      setPermissions(p);
      if (r.length > 0) setAssignRoleId(r[0].id);
    } catch (err) {
      if (err instanceof ApiError && err.status === 403) setForbidden(true);
      else setError(getErrorMessage(err, 'Could not load RBAC data.'));
    }
  }, []);

  useEffect(() => {
    void refresh();
  }, [refresh]);

  async function selectRole(roleId: string) {
    setSelectedRoleId(roleId);
    setError('');
    try {
      const perms = await getRolePermissions(roleId);
      setRolePermIds(new Set(perms.map((p) => p.id)));
    } catch (err) {
      setError(err instanceof ApiError ? err.message : "Could not load this role's permissions.");
    }
  }

  function togglePermission(id: string) {
    setRolePermIds((prev) => {
      const next = new Set(prev);
      if (next.has(id)) next.delete(id);
      else next.add(id);
      return next;
    });
  }

  async function handleSavePermissions() {
    if (!selectedRoleId) return;
    setSavingPerms(true);
    setError('');
    try {
      await setRolePermissions(selectedRoleId, Array.from(rolePermIds));
    } catch (err) {
      setError(getErrorMessage(err, 'Could not save permissions.'));
    } finally {
      setSavingPerms(false);
    }
  }

  async function handleCreateRole() {
    if (!newRoleName.trim()) return;
    setError('');
    try {
      await createRole(newRoleName.trim());
      setNewRoleName('');
      setShowNewRole(false);
      await refresh();
    } catch (err) {
      setError(getErrorMessage(err, 'Could not create this role.'));
    }
  }

  async function handleSearchUser() {
    setSearching(true);
    setError('');
    setFoundUser(null);
    setUserRoles([]);
    try {
      const user = await lookupUserByPhone(assignPhone);
      if (!user) {
        setError('No account found with that phone number.');
        return;
      }
      setFoundUser(user);
      setUserRoles(await getUserRoles(user.id));
    } catch (err) {
      setError(getErrorMessage(err, 'Could not search for this user.'));
    } finally {
      setSearching(false);
    }
  }

  async function handleAssign() {
    if (!foundUser || !assignRoleId) return;
    setError('');
    try {
      await assignRoleToUser(foundUser.id, assignRoleId);
      setUserRoles(await getUserRoles(foundUser.id));
    } catch (err) {
      setError(getErrorMessage(err, 'Could not assign this role.'));
    }
  }

  async function handleRevoke(roleId: string) {
    if (!foundUser) return;
    setError('');
    try {
      await revokeRoleFromUser(foundUser.id, roleId);
      setUserRoles(await getUserRoles(foundUser.id));
    } catch (err) {
      setError(getErrorMessage(err, 'Could not revoke this role.'));
    }
  }

  if (forbidden) {
    return (
      <Layout title="Roles & Permissions">
        <AccessDenied />
      </Layout>
    );
  }

  return (
    <Layout
      title="Roles & Permissions"
      actions={
        <Button style={{ width: 'auto', padding: '0 18px' }} onClick={() => setShowNewRole((v) => !v)}>
          {showNewRole ? 'Cancel' : 'New role'}
        </Button>
      }
    >
      {error && <p style={{ color: 'var(--danger)', fontSize: 13, marginBottom: 16 }}>{error}</p>}

      {showNewRole && (
        <div style={{ display: 'flex', gap: 8, marginBottom: 24, maxWidth: 400 }}>
          <Input placeholder="Role name (e.g. finance_reviewer)" value={newRoleName} onChange={(e) => setNewRoleName(e.target.value)} />
          <Button style={{ width: 'auto', padding: '0 18px' }} onClick={handleCreateRole}>
            Create
          </Button>
        </div>
      )}

      <div style={{ display: 'grid', gridTemplateColumns: '220px 1fr', gap: 28, marginBottom: 40 }}>
        <div>
          <h2 style={{ fontSize: 14, color: 'var(--text-muted)', marginBottom: 10 }}>Roles</h2>
          {roles === null ? (
            <SkeletonRowList count={3} />
          ) : (
            <div style={{ display: 'flex', flexDirection: 'column', gap: 2 }}>
              {roles.map((r) => (
                <button
                  key={r.id}
                  onClick={() => selectRole(r.id)}
                  style={{
                    textAlign: 'left',
                    padding: '10px 12px',
                    borderRadius: 8,
                    border: 'none',
                    background: selectedRoleId === r.id ? 'var(--surface-raised)' : 'transparent',
                    color: selectedRoleId === r.id ? 'var(--accent-strong)' : 'var(--text)',
                    cursor: 'pointer',
                    fontSize: 14,
                  }}
                >
                  {r.name}
                </button>
              ))}
            </div>
          )}
        </div>

        <div>
          {!selectedRoleId ? (
            <p style={{ color: 'var(--text-muted)' }}>Select a role to edit its permissions.</p>
          ) : (
            <>
              <div style={{ display: 'grid', gridTemplateColumns: 'repeat(3, 1fr)', gap: 8, marginBottom: 16 }}>
                {permissions.map((p) => (
                  <label
                    key={p.id}
                    style={{
                      display: 'flex',
                      alignItems: 'center',
                      gap: 8,
                      fontSize: 13,
                      padding: '6px 8px',
                      borderRadius: 6,
                      cursor: 'pointer',
                    }}
                  >
                    <input type="checkbox" checked={rolePermIds.has(p.id)} onChange={() => togglePermission(p.id)} />
                    <span style={{ fontFamily: 'var(--font-mono)' }}>
                      {p.resource}.{p.action}
                    </span>
                  </label>
                ))}
              </div>
              <Button loading={savingPerms} style={{ width: 'auto', padding: '0 20px' }} onClick={handleSavePermissions}>
                Save permissions
              </Button>
            </>
          )}
        </div>
      </div>

      <h2 style={{ fontSize: 16, marginBottom: 14 }}>Assign a role</h2>
      <div style={{ display: 'flex', gap: 8, maxWidth: 400, marginBottom: 16 }}>
        <Input placeholder="Search by phone" value={assignPhone} onChange={(e) => setAssignPhone(e.target.value)} />
        <Button loading={searching} style={{ width: 'auto', padding: '0 18px' }} onClick={handleSearchUser}>
          Find
        </Button>
      </div>

      {foundUser && (
        <div style={{ border: '1px solid var(--border)', borderRadius: 12, background: 'var(--surface)', padding: 18, maxWidth: 500 }}>
          <div style={{ fontFamily: 'var(--font-mono)', fontSize: 14, marginBottom: 4 }}>+91 {foundUser.phone}</div>
          <div style={{ fontSize: 12, color: 'var(--text-muted)', marginBottom: 14 }}>{foundUser.account_type}</div>

          <div style={{ display: 'flex', flexWrap: 'wrap', gap: 8, marginBottom: 14 }}>
            {userRoles.length === 0 ? (
              <span style={{ fontSize: 13, color: 'var(--text-muted)' }}>No roles assigned.</span>
            ) : (
              userRoles.map((r) => (
                <span
                  key={r.id}
                  style={{
                    display: 'flex',
                    alignItems: 'center',
                    gap: 6,
                    fontSize: 12,
                    background: 'var(--surface-raised)',
                    padding: '4px 10px',
                    borderRadius: 100,
                  }}
                >
                  {r.name}
                  <button
                    onClick={() => handleRevoke(r.id)}
                    style={{ background: 'none', border: 'none', color: 'var(--danger)', cursor: 'pointer', fontSize: 14, lineHeight: 1 }}
                    aria-label={`Revoke ${r.name}`}
                  >
                    ×
                  </button>
                </span>
              ))
            )}
          </div>

          <div style={{ display: 'flex', gap: 8 }}>
            <select
              value={assignRoleId}
              onChange={(e) => setAssignRoleId(e.target.value)}
              style={{ background: 'var(--bg)', border: '1px solid var(--border)', borderRadius: 8, color: 'var(--text)', padding: '8px 10px', flex: 1 }}
            >
              {(roles || []).map((r) => (
                <option key={r.id} value={r.id}>
                  {r.name}
                </option>
              ))}
            </select>
            <Button style={{ width: 'auto', padding: '0 16px' }} onClick={handleAssign}>
              Grant
            </Button>
          </div>
        </div>
      )}
    </Layout>
  );
}
