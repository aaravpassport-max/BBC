import { Request, Response, NextFunction } from 'express';
import { ApiError } from '../utils/errors';

/**
 * Renders every error as the PRD Section 23 standard envelope:
 *   { error: { code, message, details } }
 * Never leaks a raw stack trace, driver error, or internal message to the
 * client (PRD Section 27). Unrecognized errors are logged in full server-side
 * but reduced to a generic INTERNAL_ERROR for the response.
 */
export function errorHandler(
  err: unknown,
  _req: Request,
  res: Response,
  _next: NextFunction
): void {
  if (err instanceof ApiError) {
    res.status(err.statusCode).json({
      error: {
        code: err.code,
        message: err.message,
        details: err.details,
      },
    });
    return;
  }

  // Unexpected error — never trust its message to be safe to show a user.
  console.error('Unhandled error:', err);
  res.status(500).json({
    error: {
      code: 'INTERNAL_ERROR',
      message: 'Something went wrong on our end. Please try again.',
      details: {},
    },
  });
}

/** Wraps an async route handler so a rejected promise reaches errorHandler. */
export function asyncHandler(
  fn: (req: Request, res: Response, next: NextFunction) => Promise<void>
) {
  return (req: Request, res: Response, next: NextFunction): void => {
    fn(req, res, next).catch(next);
  };
}
