import { useEffect, useRef } from 'react';

const WS_BASE = (import.meta.env.VITE_API_BASE_URL || 'http://localhost:3000').replace(/^http/, 'ws');

export interface RealtimeHandlers {
  onStatusChange?: (status: string) => void;
  onDriverLocation?: (lat: number, lng: number) => void;
  onChatMessage?: (payload: { message_id: string; sender_role: string; body: string; created_at: string }) => void;
}

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
        const msg = JSON.parse(event.data as string) as Record<string, unknown>;
        if (msg.event === 'booking.status' && typeof msg.status === 'string') {
          handlersRef.current.onStatusChange?.(msg.status);
        }
        if (msg.event === 'driver.location' && typeof msg.lat === 'number' && typeof msg.lng === 'number') {
          handlersRef.current.onDriverLocation?.(msg.lat, msg.lng);
        }
        if (msg.event === 'chat.message' && msg.message_id && msg.body) {
          handlersRef.current.onChatMessage?.({
            message_id: msg.message_id as string,
            sender_role: (msg.sender_role as string) || 'customer',
            body: msg.body as string,
            created_at: (msg.created_at as string) || new Date().toISOString(),
          });
        }
      } catch {
        // ignore malformed messages
      }
    };

    return () => ws.close();
  }, [bookingId]);
}
