import { useEffect, useRef } from 'react';

const WS_BASE = (import.meta.env.VITE_API_BASE_URL || 'http://localhost:3000').replace(/^http/, 'ws');

/** Live SOS queue updates via WebSocket — supplements polling on SosQueuePage. */
export function useOpsRealtime(onSosTriggered: () => void): void {
  const callbackRef = useRef(onSosTriggered);
  callbackRef.current = onSosTriggered;

  useEffect(() => {
    const token = localStorage.getItem('access_token');
    if (!token) return;

    const url = `${WS_BASE}/v1/realtime/ws?token=${encodeURIComponent(token)}&ops=true`;
    const ws = new WebSocket(url);

    ws.onmessage = (event) => {
      try {
        const msg = JSON.parse(event.data as string) as { event?: string };
        if (msg.event === 'sos.triggered') {
          callbackRef.current();
        }
      } catch {
        // ignore
      }
    };

    return () => ws.close();
  }, []);
}
