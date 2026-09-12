import { useState, useEffect } from 'react';
import { Button } from './Button';
import { getTipPresets, submitTip, getErrorMessage } from '../api';

interface TipPanelProps {
  bookingId: string;
  onTipped: () => void;
}

export function TipPanel({ bookingId, onTipped }: TipPanelProps) {
  const [presets, setPresets] = useState<number[]>([20, 50, 100]);
  const [custom, setCustom] = useState('');
  const [loading, setLoading] = useState(false);
  const [error, setError] = useState('');

  useEffect(() => {
    getTipPresets()
      .then((r) => setPresets(r.amounts))
      .catch(() => undefined);
  }, []);

  async function handleTip(amount: number) {
    setLoading(true);
    setError('');
    try {
      await submitTip(bookingId, amount);
      onTipped();
    } catch (err) {
      setError(getErrorMessage(err, 'Could not send tip.'));
    } finally {
      setLoading(false);
    }
  }

  return (
    <div
      style={{
        border: '1px solid var(--border)',
        borderRadius: 12,
        padding: 18,
        background: 'var(--surface)',
      }}
    >
      <div style={{ fontSize: 14, fontWeight: 600, marginBottom: 4 }}>Tip your driver</div>
      <p style={{ fontSize: 13, color: 'var(--text-muted)', marginBottom: 14 }}>
        100% goes to your driver. Available for 24 hours after trip completion.
      </p>
      <div style={{ display: 'flex', gap: 8, flexWrap: 'wrap', marginBottom: 12 }}>
        {presets.map((amt) => (
          <Button
            key={amt}
            variant="ghost"
            style={{ width: 'auto', padding: '8px 16px', flex: '1 1 auto', minWidth: 64 }}
            loading={loading}
            onClick={() => void handleTip(amt)}
          >
            ₹{amt}
          </Button>
        ))}
      </div>
      <div style={{ display: 'flex', gap: 8 }}>
        <input
          type="number"
          min={1}
          max={5000}
          placeholder="Custom amount"
          value={custom}
          onChange={(e) => setCustom(e.target.value)}
          style={{
            flex: 1,
            padding: '10px 12px',
            borderRadius: 8,
            border: '1px solid var(--border)',
            background: 'var(--bg)',
            color: 'var(--text)',
          }}
        />
        <Button
          style={{ width: 'auto', padding: '0 18px' }}
          loading={loading}
          disabled={!custom || parseFloat(custom) <= 0}
          onClick={() => void handleTip(parseFloat(custom))}
        >
          Send
        </Button>
      </div>
      {error && <p style={{ color: 'var(--danger)', fontSize: 13, marginTop: 10 }}>{error}</p>}
    </div>
  );
}
