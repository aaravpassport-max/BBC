import { useEffect, useRef } from 'react';

const WS_BASE = (import.meta.env.VITE_API_BASE_URL || 'http://localhost:3000').replace(/^http/, 'ws');

export interface RealtimeHandlers {
  onStatusChange?: (status: string) => void;
  onDriverLocation?: (lat: number, lng: number) => void;
}

/** WebSocket subscription for live trip updates — supplements polling on TrackPage. */
export function useBookingRealtime(bookingId: string | undefined, handlers: RealtimeHandlers): void {
  const handlersRef = useRef(handlers);
  handlersRef.current = handlers;

  useEffect(() => {
    if (!bookingId) return;
    const token = localStorage.getItem('access_token');
    if (!token) return;

    const url = `${WS_BASE}/v1/realtime/ws?token=${encodeURIComponent(token)}&booking_id=${encodeURIComponent(bookingId)}`;
    const ws = new WebSocket(url);

    ws.onmessage = (event) => {
      try {
        const msg = JSON.parse(event.data as string) as {
          event?: string;
          status?: string;
          lat?: number;
          lng?: number;
        };
        if (msg.event === 'booking.status' && msg.status) {
          handlersRef.current.onStatusChange?.(msg.status);
        }
        if (msg.event === 'driver.location' && typeof msg.lat === 'number' && typeof msg.lng === 'number') {
          handlersRef.current.onDriverLocation?.(msg.lat, msg.lng);
        }
      } catch {
        // ignore malformed messages
      }
    };

    return () => ws.close();
  }, [bookingId]);
}
