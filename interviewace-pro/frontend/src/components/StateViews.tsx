import type { ReactNode } from 'react';
import type { ApiError } from '@/types';

/**
 * Shared loading/empty/error primitives, used everywhere instead of each
 * screen inventing its own. Direct fix for audit item "Part 11 — every
 * major component must have loading/empty/error/retry states" — centralizing
 * them means every screen gets consistent behavior for free, including the
 * retryable-vs-not distinction the backend now reports on every error.
 */

export function LoadingBlock({ label = 'Loading…' }: { label?: string }) {
  return (
    <div className="ia-state ia-state-loading" role="status" aria-live="polite">
      <div className="ia-spinner" aria-hidden="true" />
      <p>{label}</p>
    </div>
  );
}

export function EmptyBlock({ title, hint, action }: { title: string; hint?: string; action?: ReactNode }) {
  return (
    <div className="ia-state ia-state-empty">
      <h3>{title}</h3>
      {hint && <p>{hint}</p>}
      {action}
    </div>
  );
}

export function ErrorBlock({
  error,
  onRetry,
  fallbackMessage = 'Something went wrong.',
}: {
  error: ApiError | Error | unknown;
  onRetry?: () => void;
  fallbackMessage?: string;
}) {
  const apiErr = error as Partial<ApiError>;
  const message = apiErr?.message || fallbackMessage;
  const canRetry = Boolean(onRetry) && apiErr?.retryable !== false;

  return (
    <div className="ia-state ia-state-error" role="alert">
      <h3>We hit a snag</h3>
      <p>{message}</p>
      {canRetry && onRetry && (
        <button className="ia-btn ia-btn-primary" onClick={onRetry}>
          Try again
        </button>
      )}
      {!canRetry && apiErr?.code === 'max_retries' && (
        <p className="ia-state-hint">This has failed repeatedly — please contact support.</p>
      )}
    </div>
  );
}
