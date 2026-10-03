import { useEffect, useRef } from 'react';

const WS_BASE = (import.meta.env.VITE_API_BASE_URL || 'http://localhost:3000').replace(/^http/, 'ws');

type OpsEventHandler = (event: Record<string, unknown>) => void;

/** Live ops events via WebSocket — SOS triggers, driver location updates, etc. */
export function useOpsRealtime(onEvent: OpsEventHandler): void {
  const callbackRef = useRef(onEvent);
  callbackRef.current = onEvent;

  useEffect(() => {
    const token = localStorage.getItem('access_token');
    if (!token) return;

    const url = `${WS_BASE}/v1/realtime/ws?token=${encodeURIComponent(token)}&ops=true`;
    const ws = new WebSocket(url);

    ws.onmessage = (event) => {
      try {
        const msg = JSON.parse(event.data as string) as Record<string, unknown>;
        if (msg.channel === 'ops' || msg.event) {
          callbackRef.current(msg);
        }
      } catch {
        // ignore
      }
    };

    return () => ws.close();
  }, []);
}

/** Convenience wrapper for SOS queue refresh. */
export function useOpsSosRealtime(onSosTriggered: () => void): void {
  useOpsRealtime((msg) => {
    if (msg.event === 'sos.triggered') onSosTriggered();
  });
}
