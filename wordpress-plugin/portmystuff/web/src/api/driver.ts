import { api } from '@/api/client';

export type DriverDashboard = {
  trips_today: number;
  gross_earnings_today: number;
  rating_avg: number;
};

export type DriverOffer = {
  offer_id: string;
  booking_id: string;
  booking_type: string;
  fare_breakdown: { final_fare: number };
};

export function driverDashboard() {
  return api<DriverDashboard>('/driver/dashboard');
}

export function setOnline(online: boolean) {
  return api('/driver/status', { method: 'POST', body: JSON.stringify({ online }) });
}

export function updateLocation(lat: number, lng: number) {
  return api('/driver/location', { method: 'POST', body: JSON.stringify({ lat, lng }) });
}

export function pendingOffer() {
  return api<DriverOffer | null>('/driver/jobs/pending-offer');
}

export function acceptOffer(offerId: string) {
  return api(`/driver/jobs/offers/${offerId}/accept`, { method: 'POST' });
}

export function rejectOffer(offerId: string) {
  return api(`/driver/jobs/offers/${offerId}/reject`, { method: 'POST' });
}

export function activeJob() {
  return api<{ id: string; status: string } | null>('/driver/jobs/active');
}

export function advanceJob(bookingId: string, status: string) {
  return api<{ id: string; status: string }>(`/driver/jobs/${bookingId}/status`, {
    method: 'POST',
    body: JSON.stringify({ status }),
  });
}
