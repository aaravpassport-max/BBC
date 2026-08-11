import { useEffect, useRef } from 'react';
import { isDemoSession } from '../api/demoAuth';

const WS_BASE = (import.meta.env.VITE_API_BASE_URL || 'http://localhost:3000').replace(/^http/, 'ws');

export interface DriverRealtimeHandlers {
  enabled?: boolean;
  onNewOffer?: (payload: { offer_id: string; booking_id: string; expires_at: string }) => void;
}

/** WebSocket subscription for driver-specific events (new offers, etc.). */
export function useDriverRealtime(handlers: DriverRealtimeHandlers): void {
  const handlersRef = useRef(handlers);
  handlersRef.current = handlers;

  useEffect(() => {
    if (handlers.enabled === false || isDemoSession()) return;

    const token = localStorage.getItem('access_token');
    if (!token) return;

    const url = `${WS_BASE}/v1/realtime/ws?token=${encodeURIComponent(token)}`;
    const ws = new WebSocket(url);

    ws.onmessage = (event) => {
      try {
        const msg = JSON.parse(event.data as string) as {
          event?: string;
          offer_id?: string;
          booking_id?: string;
          expires_at?: string;
        };
        if (msg.event === 'dispatch.offer' && msg.offer_id && msg.booking_id && msg.expires_at) {
          handlersRef.current.onNewOffer?.({
            offer_id: msg.offer_id,
            booking_id: msg.booking_id,
            expires_at: msg.expires_at,
          });
        }
      } catch {
        // ignore malformed messages
      }
    };

    return () => ws.close();
  }, [handlers.enabled]);
}
