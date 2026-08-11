import { Router } from 'express';
import { z } from 'zod';
import { asyncHandler } from '../../middleware/errorHandler';
import { validateBody } from '../../middleware/validate';
import { requireAuth, requirePermission } from '../../middleware/auth';
import {
  listRoles,
  listPermissions,
  getRolePermissions,
  createRole,
  setRolePermissions,
  assignRoleToUser,
  revokeRoleFromUser,
  getUserRoles,
} from './rbac.service';
import { findUserByPhone } from './admin.service';

const createRoleSchema = z.object({ name: z.string().min(1), description: z.string().optional() });
const setPermissionsSchema = z.object({ permission_ids: z.array(z.string().uuid()) });
const assignRoleSchema = z.object({
  user_id: z.string().uuid(),
  role_id: z.string().uuid(),
  scope: z.record(z.string(), z.unknown()).optional(),
});

export const rbacRouter = Router();

// PRD 22A.1/22A.2 — every RBAC management action requires rbac.role_manage
// itself, enforced at the API layer (the guard-rails in rbac.service ensure
// this permission can never be fully removed from the system by using it).
rbacRouter.get(
  '/roles',
  requireAuth,
  requirePermission('rbac', 'role_manage'),
  asyncHandler(async (_req, res) => {
    res.status(200).json(await listRoles());
  })
);

rbacRouter.get(
  '/permissions',
  requireAuth,
  requirePermission('rbac', 'role_manage'),
  asyncHandler(async (_req, res) => {
    res.status(200).json(await listPermissions());
  })
);

rbacRouter.get(
  '/roles/:id/permissions',
  requireAuth,
  requirePermission('rbac', 'role_manage'),
  asyncHandler(async (req, res) => {
    res.status(200).json(await getRolePermissions(req.params.id as string));
  })
);

rbacRouter.post(
  '/roles',
  requireAuth,
  requirePermission('rbac', 'role_manage'),
  validateBody(createRoleSchema),
  asyncHandler(async (req, res) => {
    const result = await createRole(req.body.name, req.body.description);
    res.status(201).json(result);
  })
);

rbacRouter.put(
  '/roles/:id/permissions',
  requireAuth,
  requirePermission('rbac', 'role_manage'),
  validateBody(setPermissionsSchema),
  asyncHandler(async (req, res) => {
    await setRolePermissions(req.params.id as string, req.body.permission_ids);
    res.status(200).json({ updated: true });
  })
);

rbacRouter.post(
  '/user-roles',
  requireAuth,
  requirePermission('rbac', 'role_manage'),
  validateBody(assignRoleSchema),
  asyncHandler(async (req, res) => {
    const { user_id, role_id, scope } = req.body;
    await assignRoleToUser({ userId: user_id, roleId: role_id, scope, grantedBy: req.user!.userId });
    res.status(200).json({ assigned: true });
  })
);

rbacRouter.delete(
  '/user-roles/:userId/:roleId',
  requireAuth,
  requirePermission('rbac', 'role_manage'),
  asyncHandler(async (req, res) => {
    await revokeRoleFromUser(req.params.userId as string, req.params.roleId as string);
    res.status(200).json({ revoked: true });
  })
);

rbacRouter.get(
  '/users/lookup',
  requireAuth,
  requirePermission('rbac', 'role_manage'),
  asyncHandler(async (req, res) => {
    const phone = typeof req.query.phone === 'string' ? req.query.phone : '';
    if (!phone) {
      res.status(200).json(null);
      return;
    }
    const user = await findUserByPhone(phone);
    res.status(200).json(user);
  })
);

rbacRouter.get(
  '/users/:userId/roles',
  requireAuth,
  requirePermission('rbac', 'role_manage'),
  asyncHandler(async (req, res) => {
    res.status(200).json(await getUserRoles(req.params.userId as string));
  })
);
