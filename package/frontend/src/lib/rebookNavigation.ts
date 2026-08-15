import type { NavigateFunction } from 'react-router-dom';
import { getRebookPrefill } from '../api/rebook';
import { getBooking, getErrorMessage } from '../api';
import { rebookResponseToDraft, bookingDetailToDraft, snapshotPayloadToDraft } from './bookingDraft';
import type { BookingDraft } from '../api/vehicles';
import type { BookingTemplate } from './bookingDraft';

export async function loadRebookDraft(bookingId: string): Promise<BookingDraft> {
  try {
    const prefill = await getRebookPrefill(bookingId);
    return rebookResponseToDraft(prefill);
  } catch {
    const booking = await getBooking(bookingId);
    return bookingDetailToDraft(booking);
  }
}

export function startRebook(
  navigate: NavigateFunction,
  draft: BookingDraft,
  bookingId: string
): void {
  navigate('/book', {
    state: {
      serviceId: draft.serviceId,
      draft,
      entrySource: 'rebook',
      rebookFromBookingId: bookingId,
    },
  });
}

export async function rebookFromHistory(
  navigate: NavigateFunction,
  bookingId: string,
  onError?: (message: string) => void
): Promise<void> {
  try {
    const draft = await loadRebookDraft(bookingId);
    startRebook(navigate, draft, bookingId);
  } catch (err) {
    onError?.(getErrorMessage(err, 'Could not load this trip for rebooking.'));
  }
}

export function startFromTemplate(navigate: NavigateFunction, template: BookingTemplate): void {
  const draft = snapshotPayloadToDraft(template.snapshot);
  navigate('/book', {
    state: {
      serviceId: draft.serviceId,
      draft,
      entrySource: 'template',
      templateId: template.id,
    },
  });
}
