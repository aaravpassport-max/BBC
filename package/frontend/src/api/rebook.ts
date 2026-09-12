import { api } from './client';
import type { BookingDraft } from './vehicles';
import { buildRebookSnapshot, type BookingTemplate, type RebookPrefillResponse } from '../lib/bookingDraft';

export function getRebookPrefill(bookingId: string) {
  return api.get<RebookPrefillResponse>(`/v1/bookings/${bookingId}/rebook`);
}

export function listBookingTemplates() {
  return api.get<BookingTemplate[]>('/v1/booking-templates');
}

export function saveBookingTemplate(name: string, draft: BookingDraft) {
  return api.post<BookingTemplate>('/v1/booking-templates', {
    name,
    snapshot: buildRebookSnapshot(draft),
  });
}

export function deleteBookingTemplate(id: string) {
  return api.del<{ deleted: boolean }>(`/v1/booking-templates/${id}`);
}
