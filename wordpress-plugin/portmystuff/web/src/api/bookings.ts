import { api } from '@/api/client';

export type FareBreakdown = { final_fare: number; base_fare?: number; distance_charge?: number };
export type Quote = {
  quote_id: string;
  vehicle_category: string;
  fare_breakdown: FareBreakdown;
};
export type Booking = {
  id: string;
  status: string;
  booking_type: string;
  fare_breakdown?: FareBreakdown;
  pickup_otp?: string;
};

export function getQuotes(bookingType: 'ride' | 'parcel') {
  return api<{ quotes: Quote[] }>('/pricing/quote', {
    method: 'POST',
    body: JSON.stringify({
      booking_type: bookingType,
      pickup: { lat: 12.9716, lng: 77.5946 },
      drops: [{ lat: 12.9352, lng: 77.6245 }],
    }),
  });
}

export function createBooking(quoteId: string) {
  return api<Booking>('/bookings', {
    method: 'POST',
    headers: { 'Idempotency-Key': `wp-${Date.now()}` },
    body: JSON.stringify({ quote_id: quoteId, payment_method: 'upi', passenger_count: 1 }),
  });
}

export function listBookings() {
  return api<{ items: Booking[] }>('/bookings');
}

export function getBooking(id: string) {
  return api<Booking>(`/bookings/${id}`);
}

export function triggerSos(id: string) {
  return api('/bookings/' + id + '/sos', {
    method: 'POST',
    body: JSON.stringify({ lat: 12.97, lng: 77.59 }),
  });
}
