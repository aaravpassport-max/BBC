import { WebSocket, WebSocketServer } from 'ws';
import type { Server } from 'http';
import jwt from 'jsonwebtoken';
import { URL } from 'url';

interface ClientMeta {
  userId: string;
  bookingIds: Set<string>;
  ops: boolean;
}

const clients = new Map<WebSocket, ClientMeta>();

export function broadcastBookingEvent(bookingId: string, payload: Record<string, unknown>): void {
  const message = JSON.stringify({ channel: 'booking', booking_id: bookingId, ...payload });
  for (const [ws, meta] of clients) {
    if (ws.readyState === WebSocket.OPEN && meta.bookingIds.has(bookingId)) {
      ws.send(message);
    }
  }
}

export function broadcastUserEvent(userId: string, payload: Record<string, unknown>): void {
  const message = JSON.stringify({ channel: 'user', user_id: userId, ...payload });
  for (const [ws, meta] of clients) {
    if (ws.readyState === WebSocket.OPEN && meta.userId === userId) {
      ws.send(message);
    }
  }
}

export function broadcastOpsEvent(payload: Record<string, unknown>): void {
  const message = JSON.stringify({ channel: 'ops', ...payload });
  for (const [ws, meta] of clients) {
    if (ws.readyState === WebSocket.OPEN && meta.ops) {
      ws.send(message);
    }
  }
}

export function attachRealtimeServer(server: Server): void {
  const wss = new WebSocketServer({ server, path: '/v1/realtime/ws' });

  wss.on('connection', (ws, req) => {
    try {
      const url = new URL(req.url || '', 'http://localhost');
      const token = url.searchParams.get('token');
      const bookingId = url.searchParams.get('booking_id');
      const ops = url.searchParams.get('ops') === 'true';

      if (!token) {
        ws.close(4001, 'Missing token');
        return;
      }

      const payload = jwt.verify(token, process.env.JWT_ACCESS_SECRET as string) as { sub: string };
      const meta: ClientMeta = { userId: payload.sub, bookingIds: new Set(), ops };
      if (bookingId) meta.bookingIds.add(bookingId);
      clients.set(ws, meta);

      ws.on('message', (data) => {
        try {
          const msg = JSON.parse(data.toString()) as { subscribe_booking?: string };
          if (msg.subscribe_booking) meta.bookingIds.add(msg.subscribe_booking);
        } catch {
          // ignore malformed client messages
        }
      });

      ws.on('close', () => clients.delete(ws));
      ws.send(JSON.stringify({ type: 'connected' }));
    } catch {
      ws.close(4003, 'Unauthorized');
    }
  });
}
