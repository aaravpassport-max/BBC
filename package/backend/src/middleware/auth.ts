import { Request, Response, NextFunction } from 'express';
import jwt from 'jsonwebtoken';
import { Errors } from '../utils/errors';

export interface AuthenticatedUser {
  userId: string;
  accountType: string;
}

// Augment Express's Request type so downstream handlers get typed req.user.
declare global {
  // eslint-disable-next-line @typescript-eslint/no-namespace
  namespace Express {
    interface Request {
      user?: AuthenticatedUser;
    }
  }
}

/**
 * Verifies the bearer JWT and attaches the authenticated user to the request.
 * PRD Section 27 rule: authorization is re-checked on every request server-side —
 * this middleware runs on every protected route, there is no session-based
 * grandfathering of stale permissions (PRD Section 22A.1).
 */
export function requireAuth(req: Request, _res: Response, next: NextFunction): void {
  const header = req.headers.authorization;
  if (!header || !header.startsWith('Bearer ')) {
    throw Errors.unauthorized();
  }

  const token = header.slice('Bearer '.length);
  try {
    const payload = jwt.verify(token, process.env.JWT_ACCESS_SECRET as string) as {
      sub: string;
      account_type: string;
    };
    req.user = { userId: payload.sub, accountType: payload.account_type };
    next();
  } catch {
    throw Errors.unauthorized();
  }
}

/**
 * RBAC gate for admin-facing routes (PRD Section 22). Checks the caller's
 * current role/permission set live from the database on every call — never
 * trusts a claim baked into the JWT, since a role can be reduced mid-session
 * and PRD 22A.1 requires that to take effect on the very next API call.
 */
export function requirePermission(resource: string, action: string) {
  return async (req: Request, _res: Response, next: NextFunction): Promise<void> => {
    if (!req.user) throw Errors.unauthorized();

    const { pool } = await import('../db/pool');
    const result = await pool.query(
      `SELECT 1
       FROM user_roles ur
       JOIN role_permissions rp ON rp.role_id = ur.role_id
       JOIN permissions p ON p.id = rp.permission_id
       WHERE ur.user_id = $1 AND p.resource = $2 AND p.action = $3
       LIMIT 1`,
      [req.user.userId, resource, action]
    );

    if (result.rowCount === 0) {
      throw Errors.forbidden(`Missing permission: ${resource}.${action}`);
    }
    next();
  };
}
