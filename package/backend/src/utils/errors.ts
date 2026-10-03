/**
 * Every error thrown from a route handler should be one of these, so the
 * global error middleware (see middleware/errorHandler.ts) can render the
 * consistent { error: { code, message, details } } envelope required by
 * PRD Section 23 — never a raw stack trace or driver-level error leaking
 * to the client (PRD Section 27 input/output handling rule).
 */
export class ApiError extends Error {
  constructor(
    public statusCode: number,
    public code: string,
    message: string,
    public details: Record<string, unknown> = {}
  ) {
    super(message);
    this.name = 'ApiError';
  }
}

export const Errors = {
  quoteExpired: () =>
    new ApiError(409, 'QUOTE_EXPIRED', 'This price quote has expired. Please get a new quote.'),

  quoteAlreadyUsed: () =>
    new ApiError(409, 'QUOTE_ALREADY_USED', 'This quote has already been used for another booking.'),

  itemCategoryIncompatible: (category: string) =>
    new ApiError(
      422,
      'ITEM_CATEGORY_INCOMPATIBLE',
      `The selected item details are not compatible with the ${category} vehicle category.`
    ),

  otpRateLimited: (retryAfterSeconds: number) =>
    new ApiError(429, 'OTP_RATE_LIMITED', 'Too many OTP requests. Please try again shortly.', {
      retry_after_seconds: retryAfterSeconds,
    }),

  otpIncorrect: (attemptsRemaining: number) =>
    new ApiError(401, 'OTP_INCORRECT', 'The code you entered is incorrect.', {
      attempts_remaining: attemptsRemaining,
    }),

  otpLocked: (lockedUntil: string) =>
    new ApiError(423, 'OTP_LOCKED', 'Too many incorrect attempts. Please try again later.', {
      locked_until: lockedUntil,
    }),

  otpExpiredOrInvalid: () =>
    new ApiError(400, 'OTP_EXPIRED_OR_INVALID', 'This code has expired or is invalid. Request a new one.'),

  unauthorized: () => new ApiError(401, 'UNAUTHORIZED', 'Authentication required.'),

  forbidden: (message = 'You do not have permission to perform this action.') =>
    new ApiError(403, 'FORBIDDEN', message),

  notFound: (resource: string) => new ApiError(404, 'NOT_FOUND', `${resource} not found.`),

  alreadyCancelled: (by: 'driver' | 'customer' | 'system') =>
    new ApiError(409, 'ALREADY_CANCELLED', 'This booking has already been cancelled.', {
      already_cancelled_by: by,
    }),

  activeTripExists: () =>
    new ApiError(409, 'ACTIVE_TRIP_EXISTS', 'You have an active trip in progress.'),

  driverIneligible: (reason: string) =>
    new ApiError(403, 'DRIVER_INELIGIBLE', reason),

  insufficientBalance: () =>
    new ApiError(422, 'INSUFFICIENT_BALANCE', 'Your wallet balance is too low for this action.'),

  creditLimitExceeded: () =>
    new ApiError(402, 'CREDIT_LIMIT_EXCEEDED', "This booking would exceed your company's available credit."),

  validation: (details: Record<string, unknown>) =>
    new ApiError(400, 'VALIDATION_ERROR', 'One or more fields are invalid.', details),

  internal: () =>
    new ApiError(500, 'INTERNAL_ERROR', 'Something went wrong on our end. Please try again.'),
};
