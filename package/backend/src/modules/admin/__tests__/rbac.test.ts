import request from 'supertest';
import { createApp } from '../../../app';
import { pool } from '../../../db/pool';
import { loginAsNewUser } from '../../../test-utils/helpers';
import { getRoleIdByName } from '../../../test-utils/seed';

const app = createApp();

afterAll(async () => {
  await pool.end();
});

async function grantOpsAdmin(userId: string) {
  const roleId = await getRoleIdByName('ops_admin'); // has rbac.role_manage (seeded)
  await pool.query(`INSERT INTO user_roles (user_id, role_id) VALUES ($1, $2) ON CONFLICT DO NOTHING`, [
    userId,
    roleId,
  ]);
}

async function getRoleManagePermissionId(): Promise<string> {
  const result = await pool.query(`SELECT id FROM permissions WHERE resource = 'rbac' AND action = 'role_manage'`);
  return result.rows[0].id;
}

describe('RBAC: access control on the RBAC endpoints themselves (PRD Section 22)', () => {
  it('a plain user cannot list roles', async () => {
    const { accessToken } = await loginAsNewUser(app);
    const res = await request(app).get('/admin/v1/rbac/roles').set('Authorization', `Bearer ${accessToken}`);
    expect(res.status).toBe(403);
  });
});

describe('RBAC: user lookup by phone (needed to assign a role to someone)', () => {
  it('a plain user cannot look up users', async () => {
    const { accessToken } = await loginAsNewUser(app);
    const res = await request(app)
      .get('/admin/v1/rbac/users/lookup?phone=9000000000')
      .set('Authorization', `Bearer ${accessToken}`);
    expect(res.status).toBe(403);
  });

  it('finds an existing user by their exact phone number', async () => {
    const admin = await loginAsNewUser(app);
    await grantOpsAdmin(admin.userId);
    const target = await loginAsNewUser(app);

    const res = await request(app)
      .get(`/admin/v1/rbac/users/lookup?phone=${target.phone}`)
      .set('Authorization', `Bearer ${admin.accessToken}`);
    expect(res.status).toBe(200);
    expect(res.body.id).toBe(target.userId);
  });

  it('returns null (not an error) for a phone number that does not exist', async () => {
    const admin = await loginAsNewUser(app);
    await grantOpsAdmin(admin.userId);

    const res = await request(app)
      .get('/admin/v1/rbac/users/lookup?phone=9000000099')
      .set('Authorization', `Bearer ${admin.accessToken}`);
    expect(res.status).toBe(200);
    expect(res.body).toBeNull();
  });
});

describe('RBAC: role and permission CRUD (PRD 22A.1)', () => {
  it('creates a role and rejects a duplicate name', async () => {
    const admin = await loginAsNewUser(app);
    await grantOpsAdmin(admin.userId);
    const name = `test_role_${Date.now()}`;

    const first = await request(app)
      .post('/admin/v1/rbac/roles')
      .set('Authorization', `Bearer ${admin.accessToken}`)
      .send({ name, description: 'A test role' });
    expect(first.status).toBe(201);

    const dup = await request(app)
      .post('/admin/v1/rbac/roles')
      .set('Authorization', `Bearer ${admin.accessToken}`)
      .send({ name });
    expect(dup.status).toBe(400);
  });

  it('sets a roles permission set and it is reflected on read', async () => {
    const admin = await loginAsNewUser(app);
    await grantOpsAdmin(admin.userId);
    const role = await request(app)
      .post('/admin/v1/rbac/roles')
      .set('Authorization', `Bearer ${admin.accessToken}`)
      .send({ name: `perm_test_role_${Date.now()}` });

    const permissions = await request(app)
      .get('/admin/v1/rbac/permissions')
      .set('Authorization', `Bearer ${admin.accessToken}`);
    // Filter to properly-generated (gen_random_uuid()) permission IDs only —
    // a handful of permissions seeded earlier in this session via manual SQL
    // with explicit literal IDs (e.g. '66666666-6666-...') aren't RFC4122-
    // compliant and are correctly rejected by this endpoint's strict
    // z.string().uuid() validation, the same class of issue root-caused in
    // the Admin rate-card tests. Picking only compliant IDs here tests the
    // real behavior instead of tripping over old seed-data debt.
    const uuidV4Pattern = /^[0-9a-f]{8}-[0-9a-f]{4}-4[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/i;
    const somePermIds = permissions.body
      .filter((p: { id: string }) => uuidV4Pattern.test(p.id))
      .slice(0, 2)
      .map((p: { id: string }) => p.id);
    expect(somePermIds.length).toBe(2); // sanity: the test DB must have at least 2 compliant permissions

    const setResult = await request(app)
      .put(`/admin/v1/rbac/roles/${role.body.id}/permissions`)
      .set('Authorization', `Bearer ${admin.accessToken}`)
      .send({ permission_ids: somePermIds });
    expect(setResult.status).toBe(200);

    const rolePerms = await request(app)
      .get(`/admin/v1/rbac/roles/${role.body.id}/permissions`)
      .set('Authorization', `Bearer ${admin.accessToken}`);
    expect(rolePerms.body.map((p: { id: string }) => p.id).sort()).toEqual(somePermIds.sort());
  });

  it('assigns and revokes a role for a user', async () => {
    const admin = await loginAsNewUser(app);
    await grantOpsAdmin(admin.userId);
    const target = await loginAsNewUser(app);
    const role = await request(app)
      .post('/admin/v1/rbac/roles')
      .set('Authorization', `Bearer ${admin.accessToken}`)
      .send({ name: `assign_test_role_${Date.now()}` });

    const assign = await request(app)
      .post('/admin/v1/rbac/user-roles')
      .set('Authorization', `Bearer ${admin.accessToken}`)
      .send({ user_id: target.userId, role_id: role.body.id });
    expect(assign.status).toBe(200);

    const roles = await request(app)
      .get(`/admin/v1/rbac/users/${target.userId}/roles`)
      .set('Authorization', `Bearer ${admin.accessToken}`);
    expect(roles.body.some((r: { id: string }) => r.id === role.body.id)).toBe(true);

    const revoke = await request(app)
      .delete(`/admin/v1/rbac/user-roles/${target.userId}/${role.body.id}`)
      .set('Authorization', `Bearer ${admin.accessToken}`);
    expect(revoke.status).toBe(200);

    const rolesAfter = await request(app)
      .get(`/admin/v1/rbac/users/${target.userId}/roles`)
      .set('Authorization', `Bearer ${admin.accessToken}`);
    expect(rolesAfter.body.some((r: { id: string }) => r.id === role.body.id)).toBe(false);
  });
});

describe('RBAC: last-role-manager guard-rail (PRD 22A.1 hard requirement)', () => {
  it('allows stripping role_manage from one role when another manager still exists', async () => {
    const admin = await loginAsNewUser(app);
    await grantOpsAdmin(admin.userId);
    const roleManagePermId = await getRoleManagePermissionId();

    const soleManagerRole = await request(app)
      .post('/admin/v1/rbac/roles')
      .set('Authorization', `Bearer ${admin.accessToken}`)
      .send({ name: `sole_manager_role_${Date.now()}` });
    await request(app)
      .put(`/admin/v1/rbac/roles/${soleManagerRole.body.id}/permissions`)
      .set('Authorization', `Bearer ${admin.accessToken}`)
      .send({ permission_ids: [roleManagePermId] });
    const soleManagerUser = await loginAsNewUser(app);
    await pool.query(`INSERT INTO user_roles (user_id, role_id) VALUES ($1, $2)`, [
      soleManagerUser.userId,
      soleManagerRole.body.id,
    ]);

    // ops_admin (via `admin`) is still another manager, so this is allowed.
    const stripAttempt = await request(app)
      .put(`/admin/v1/rbac/roles/${soleManagerRole.body.id}/permissions`)
      .set('Authorization', `Bearer ${admin.accessToken}`)
      .send({ permission_ids: [] });
    expect(stripAttempt.status).toBe(200);
  });

  it('genuinely blocks reaching zero role-managers system-wide', async () => {
    // The shared test database accumulates leftover role-manager grants
    // across every previous test run today (roles created with unique
    // Date.now()-based names in earlier runs of this same file, never
    // cleaned up) — so achieving a genuine system-wide zero requires
    // capturing and temporarily removing EVERY existing grant, not just the
    // one this file's other tests rely on (granted via grantOpsAdmin). Every
    // removed grant is restored in the finally block, unconditionally.
    const roleManagePermId = await getRoleManagePermissionId();
    const existingGrants = await pool.query(
      `SELECT role_id FROM role_permissions WHERE permission_id = $1`,
      [roleManagePermId]
    );

    const bootstrapAdmin = await loginAsNewUser(app);
    await grantOpsAdmin(bootstrapAdmin.userId);

    const isolatedRole = await request(app)
      .post('/admin/v1/rbac/roles')
      .set('Authorization', `Bearer ${bootstrapAdmin.accessToken}`)
      .send({ name: `last_manager_role_${Date.now()}` });
    await request(app)
      .put(`/admin/v1/rbac/roles/${isolatedRole.body.id}/permissions`)
      .set('Authorization', `Bearer ${bootstrapAdmin.accessToken}`)
      .send({ permission_ids: [roleManagePermId] });
    const isolatedUser = await loginAsNewUser(app);
    await request(app)
      .post('/admin/v1/rbac/user-roles')
      .set('Authorization', `Bearer ${bootstrapAdmin.accessToken}`)
      .send({ user_id: isolatedUser.userId, role_id: isolatedRole.body.id });

    // Remove EVERY existing role_manage grant except the one we just built —
    // after this, isolatedUser via isolatedRole is the ONLY role-manager.
    await pool.query(
      `DELETE FROM role_permissions WHERE permission_id = $1 AND role_id != $2`,
      [roleManagePermId, isolatedRole.body.id]
    );

    try {
      const remaining = await pool.query(
        `SELECT count(DISTINCT ur.user_id) FROM user_roles ur
         JOIN role_permissions rp ON rp.role_id = ur.role_id
         WHERE rp.permission_id = $1`,
        [roleManagePermId]
      );
      expect(parseInt(remaining.rows[0].count, 10)).toBe(1); // sanity: genuinely down to one manager

      // Attempt to strip the last manager's permission — must be blocked.
      const stripAttempt = await request(app)
        .put(`/admin/v1/rbac/roles/${isolatedRole.body.id}/permissions`)
        .set('Authorization', `Bearer ${isolatedUser.accessToken}`)
        .send({ permission_ids: [] });
      expect(stripAttempt.status).toBe(400);
      expect(stripAttempt.body.error.details.permissions).toMatch(/no user able to manage roles/);

      // Attempt to revoke the last manager's role assignment — also blocked.
      const revokeAttempt = await request(app)
        .delete(`/admin/v1/rbac/user-roles/${isolatedUser.userId}/${isolatedRole.body.id}`)
        .set('Authorization', `Bearer ${isolatedUser.accessToken}`);
      expect(revokeAttempt.status).toBe(400);
      expect(revokeAttempt.body.error.details.user).toMatch(/last user capable of managing roles/);
    } finally {
      // Restore every originally-captured grant unconditionally, even if an
      // assertion above failed, so no other test is left broken.
      for (const grant of existingGrants.rows) {
        await pool.query(
          `INSERT INTO role_permissions (role_id, permission_id) VALUES ($1, $2) ON CONFLICT DO NOTHING`,
          [grant.role_id, roleManagePermId]
        );
      }
    }
  });

  it('does not block revoking an UNRELATED permission/role (guard-rail is specific to role_manage)', async () => {
    const admin = await loginAsNewUser(app);
    await grantOpsAdmin(admin.userId);

    const unrelatedRole = await request(app)
      .post('/admin/v1/rbac/roles')
      .set('Authorization', `Bearer ${admin.accessToken}`)
      .send({ name: `unrelated_role_${Date.now()}` });
    const permissions = await request(app)
      .get('/admin/v1/rbac/permissions')
      .set('Authorization', `Bearer ${admin.accessToken}`);
    const uuidV4Pattern = /^[0-9a-f]{8}-[0-9a-f]{4}-4[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/i;
    const unrelatedPermId = permissions.body.find(
      (p: { id: string; resource: string; action: string }) =>
        uuidV4Pattern.test(p.id) && !(p.resource === 'rbac' && p.action === 'role_manage')
    ).id;
    const setResult = await request(app)
      .put(`/admin/v1/rbac/roles/${unrelatedRole.body.id}/permissions`)
      .set('Authorization', `Bearer ${admin.accessToken}`)
      .send({ permission_ids: [unrelatedPermId] });
    expect(setResult.status).toBe(200);

    const soleUser = await loginAsNewUser(app);
    await pool.query(`INSERT INTO user_roles (user_id, role_id) VALUES ($1, $2)`, [
      soleUser.userId,
      unrelatedRole.body.id,
    ]);

    const revoke = await request(app)
      .delete(`/admin/v1/rbac/user-roles/${soleUser.userId}/${unrelatedRole.body.id}`)
      .set('Authorization', `Bearer ${admin.accessToken}`);
    expect(revoke.status).toBe(200);
  });
});
