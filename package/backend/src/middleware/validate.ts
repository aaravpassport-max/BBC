import { Request, Response, NextFunction } from 'express';
import { ZodSchema } from 'zod';
import { Errors } from '../utils/errors';

/** Validates req.body against a Zod schema, throwing the standard error envelope on failure. */
export function validateBody(schema: ZodSchema) {
  return (req: Request, _res: Response, next: NextFunction): void => {
    const result = schema.safeParse(req.body);
    if (!result.success) {
      throw Errors.validation({ issues: result.error.issues });
    }
    req.body = result.data;
    next();
  };
}
