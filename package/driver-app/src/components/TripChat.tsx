import { useState, useEffect, useRef, useCallback } from 'react';
import { getTripMessages, sendTripMessage, ApiError, type TripMessage } from '../api';
import { Button } from './Button';
import styles from './TripChat.module.css';

const POLL_INTERVAL_MS = 3000;

export function TripChat({ bookingId, myRole }: { bookingId: string; myRole: 'customer' | 'driver' }) {
  const [messages, setMessages] = useState<TripMessage[]>([]);
  const [draft, setDraft] = useState('');
  const [sending, setSending] = useState(false);
  const [error, setError] = useState('');
  const [open, setOpen] = useState(false);
  const bottomRef = useRef<HTMLDivElement>(null);
  const lastCount = useRef(0);

  const refresh = useCallback(async () => {
    try {
      const msgs = await getTripMessages(bookingId);
      setMessages(msgs);
    } catch {
      // A chat load failure shouldn't take over the whole trip screen —
      // the rest of tracking still works without it. Silently retried on
      // the next poll tick rather than surfacing a persistent error banner.
    }
  }, [bookingId]);

  useEffect(() => {
    void refresh();
    const interval = setInterval(() => void refresh(), POLL_INTERVAL_MS);
    return () => clearInterval(interval);
  }, [refresh]);

  useEffect(() => {
    if (open && messages.length > lastCount.current) {
      bottomRef.current?.scrollIntoView({ behavior: 'smooth' });
    }
    lastCount.current = messages.length;
  }, [messages, open]);

  async function handleSend() {
    if (!draft.trim()) return;
    setSending(true);
    setError('');
    try {
      await sendTripMessage(bookingId, draft.trim());
      setDraft('');
      await refresh();
    } catch (err) {
      setError(err instanceof ApiError ? err.message : 'Could not send this message.');
    } finally {
      setSending(false);
    }
  }

  if (!open) {
    return (
      <button className={styles.launcher} onClick={() => setOpen(true)}>
        💬 Message {myRole === 'customer' ? 'driver' : 'customer'}
        {messages.length > 0 && <span className={styles.badge}>{messages.length}</span>}
      </button>
    );
  }

  return (
    <div className={styles.panel}>
      <div className={styles.header}>
        <span>{myRole === 'customer' ? 'Chat with your driver' : 'Chat with customer'}</span>
        <button className={styles.close} onClick={() => setOpen(false)} aria-label="Close chat">
          ✕
        </button>
      </div>

      <div className={styles.thread}>
        {messages.length === 0 ? (
          <p className={styles.empty}>No messages yet — say hello.</p>
        ) : (
          messages.map((m) => (
            <div key={m.id} className={`${styles.bubble} ${m.sender_role === myRole ? styles.mine : styles.theirs}`}>
              {m.body}
            </div>
          ))
        )}
        <div ref={bottomRef} />
      </div>

      {error && <p className={styles.error}>{error}</p>}

      <div className={styles.composer}>
        <input
          className={styles.input}
          value={draft}
          onChange={(e) => setDraft(e.target.value)}
          onKeyDown={(e) => e.key === 'Enter' && handleSend()}
          placeholder="Type a message…"
          maxLength={1000}
        />
        <Button loading={sending} style={{ width: 'auto', padding: '0 16px' }} onClick={handleSend}>
          Send
        </Button>
      </div>
    </div>
  );
}
