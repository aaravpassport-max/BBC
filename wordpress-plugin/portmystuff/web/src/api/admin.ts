import { api } from '@/api/client';

export function adminBookings() {
  return api<{ items: Array<{ id: string; status: string; booking_type: string; created_at: string }> }>(
    '/bookings',
    { admin: true },
  );
}

export function adminDrivers() {
  return api<{ items: Array<{ id: number; phone: string; name: string; kyc_status: string; online_status: number }> }>(
    '/drivers',
    { admin: true },
  );
}

export function adminRevenue() {
  return api<{ total_trips: number; gross_revenue: number; platform_fees: number }>(
    '/analytics/revenue',
    { admin: true },
  );
}

export function opsSos() {
  return api<Array<{ id: string; booking_id: string; status: string; created_at: string }>>('/sos/queue', {
    ops: true,
  });
}

export function opsLiveDrivers() {
  return api<Array<{ driver_id: number; lat: number; lng: number; last_ping_at: string }>>('/live-map/drivers', {
    ops: true,
  });
}
