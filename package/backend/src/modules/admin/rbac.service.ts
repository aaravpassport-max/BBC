import { pool, withTransaction } from '../../db/pool';
import { Errors } from '../../utils/errors';

export async function listRoles() {
  const result = await pool.query(`SELECT id, name, description, created_at FROM roles ORDER BY name`);
  return result.rows;
}

export async function listPermissions() {
  const result = await pool.query(`SELECT id, resource, action FROM permissions ORDER BY resource, action`);
  return result.rows;
}

export async function getRolePermissions(roleId: string) {
  const result = await pool.query(
    `SELECT p.id, p.resource, p.action FROM role_permissions rp
     JOIN permissions p ON p.id = rp.permission_id WHERE rp.role_id = $1
     ORDER BY p.resource, p.action`,
    [roleId]
  );
  return result.rows;
}

export async function createRole(name: string, description?: string): Promise<{ id: string }> {
  try {
    const result = await pool.query(`INSERT INTO roles (name, description) VALUES ($1, $2) RETURNING id`, [
      name,
      description || null,
    ]);
    return { id: result.rows[0].id };
  } catch (err) {
    const pgErr = err as { code?: string };
    if (pgErr.code === '23505') {
      throw Errors.validation({ name: 'A role with this name already exists.' });
    }
    throw err;
  }
}

/**
 * Sets a role's full permission set (PRD 22A.1 role editor). Guard-rail:
 * the edit is rejected outright if it would leave zero users anywhere in
 * the system capable of managing roles (rbac.role_manage) — a hard,
 * structural check computed against every role's current+proposed
 * permission set, not just this one role in isolation, since a platform
 * could have the role-management permission spread across several roles.
 */
export async function setRolePermissions(roleId: string, permissionIds: string[]): Promise<void> {
  return withTransaction(async (client) => {
    await client.query(`SELECT id FROM roles WHERE id = $1 FOR UPDATE`, [roleId]);

    const roleManagePermission = await client.query(
      `SELECT id FROM permissions WHERE resource = 'rbac' AND action = 'role_manage'`
    );
    if (roleManagePermission.rowCount && roleManagePermission.rowCount > 0) {
      const roleManagePermId = roleManagePermission.rows[0].id;
      const wouldRetainRoleManage = permissionIds.includes(roleManagePermId);

      if (!wouldRetainRoleManage) {
        const otherRoleManagers = await client.query(
          `SELECT count(DISTINCT ur.user_id) FROM user_roles ur
           JOIN role_permissions rp ON rp.role_id = ur.role_id
           WHERE rp.permission_id = $1 AND ur.role_id != $2`,
          [roleManagePermId, roleId]
        );
        const thisRoleCurrentlyHasUsers = await client.query(`SELECT count(*) FROM user_roles WHERE role_id = $1`, [
          roleId,
        ]);
        const thisRoleCurrentlyHasPermission = await client.query(
          `SELECT 1 FROM role_permissions WHERE role_id = $1 AND permission_id = $2`,
          [roleId, roleManagePermId]
        );

        const removingTheOnlySource =
          (thisRoleCurrentlyHasPermission.rowCount || 0) > 0 &&
          parseInt(thisRoleCurrentlyHasUsers.rows[0].count, 10) > 0 &&
          parseInt(otherRoleManagers.rows[0].count, 10) === 0;

        if (removingTheOnlySource) {
          throw Errors.validation({
            permissions:
              'This change would leave no user able to manage roles. Assign rbac.role_manage to another role with active users first.',
          });
        }
      }
    }

    await client.query(`DELETE FROM role_permissions WHERE role_id = $1`, [roleId]);
    for (const permissionId of permissionIds) {
      await client.query(`INSERT INTO role_permissions (role_id, permission_id) VALUES ($1, $2)`, [
        roleId,
        permissionId,
      ]);
    }
  });
}

export async function assignRoleToUser(params: {
  userId: string;
  roleId: string;
  scope?: object;
  grantedBy: string;
}): Promise<void> {
  const { userId, roleId, scope, grantedBy } = params;
  await pool.query(
    `INSERT INTO user_roles (user_id, role_id, scope, granted_by) VALUES ($1, $2, $3, $4)
     ON CONFLICT (user_id, role_id) DO UPDATE SET scope = $3`,
    [userId, roleId, JSON.stringify(scope || {}), grantedBy]
  );
}

/**
 * Revokes a role from a user, with the same last-role-manager guard-rail as
 * setRolePermissions above — this is the OTHER way a system could end up
 * with zero role managers (removing the LAST user from a role, rather than
 * removing the permission from the role).
 */
export async function revokeRoleFromUser(userId: string, roleId: string): Promise<void> {
  return withTransaction(async (client) => {
    const existing = await client.query(`SELECT 1 FROM user_roles WHERE user_id = $1 AND role_id = $2 FOR UPDATE`, [
      userId,
      roleId,
    ]);
    if (existing.rowCount === 0) {
      throw Errors.notFound('Role assignment');
    }

    const roleManagePermission = await client.query(
      `SELECT id FROM permissions WHERE resource = 'rbac' AND action = 'role_manage'`
    );
    if (roleManagePermission.rowCount && roleManagePermission.rowCount > 0) {
      const roleManagePermId = roleManagePermission.rows[0].id;
      const thisRoleHasPermission = await client.query(
        `SELECT 1 FROM role_permissions WHERE role_id = $1 AND permission_id = $2`,
        [roleId, roleManagePermId]
      );

      if ((thisRoleHasPermission.rowCount || 0) > 0) {
        const totalRoleManagers = await client.query(
          `SELECT count(DISTINCT ur.user_id) FROM user_roles ur
           JOIN role_permissions rp ON rp.role_id = ur.role_id
           WHERE rp.permission_id = $1`,
          [roleManagePermId]
        );
        const userHasOtherRoleManageSource = await client.query(
          `SELECT 1 FROM user_roles ur
           JOIN role_permissions rp ON rp.role_id = ur.role_id
           WHERE ur.user_id = $1 AND rp.permission_id = $2 AND ur.role_id != $3`,
          [userId, roleManagePermId, roleId]
        );
        const isLastManager =
          parseInt(totalRoleManagers.rows[0].count, 10) === 1 && (userHasOtherRoleManageSource.rowCount || 0) === 0;

        if (isLastManager) {
          throw Errors.validation({
            user: 'Cannot remove the last user capable of managing roles.',
          });
        }
      }
    }

    await client.query(`DELETE FROM user_roles WHERE user_id = $1 AND role_id = $2`, [userId, roleId]);
  });
}

export async function getUserRoles(userId: string) {
  const result = await pool.query(
    `SELECT r.id, r.name, ur.scope FROM user_roles ur JOIN roles r ON r.id = ur.role_id WHERE ur.user_id = $1`,
    [userId]
  );
  return result.rows;
}
