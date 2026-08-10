import { useState, useEffect } from 'react';
import { useNavigate } from 'react-router-dom';
import { Screen } from '../components/Screen';
import { Button } from '../components/Button';
import { Input } from '../components/Input';
import { listPenalties, disputePenalty, getErrorMessage } from '../api';

export function PenaltiesPage() {
  const navigate = useNavigate();
  const [penalties, setPenalties] = useState<
    { id: string; amount: number; reason_code: string; status: string; dispute_note: string | null }[] | null
  >(null);
  const [error, setError] = useState('');
  const [disputing, setDisputing] = useState<string | null>(null);
  const [note, setNote] = useState('');

  async function refresh() {
    try {
      setPenalties(await listPenalties());
    } catch (err) {
      setError(getErrorMessage(err, 'Could not load penalties.'));
    }
  }

  useEffect(() => {
    void refresh();
  }, []);

  async function handleDispute(id: string) {
    if (!note.trim()) return;
    setDisputing(id);
    try {
      await disputePenalty(id, note.trim());
      setNote('');
      await refresh();
    } catch (err) {
      setError(getErrorMessage(err, 'Could not submit dispute.'));
    } finally {
      setDisputing(null);
    }
  }

  return (
    <Screen eyebrow="Account" title="Penalties" onBack={() => navigate('/profile')}>
      {error && <p style={{ color: 'var(--danger)', fontSize: 13 }}>{error}</p>}
      {penalties === null && <p style={{ color: 'var(--text-muted)' }}>Loading…</p>}
      {penalties && penalties.length === 0 && (
        <p style={{ color: 'var(--text-muted)', fontSize: 14 }}>No penalties on your account.</p>
      )}
      {penalties?.map((p) => (
        <div
          key={p.id}
          style={{ border: '1px solid var(--border)', borderRadius: 12, background: 'var(--surface)', padding: 14 }}
        >
          <div style={{ fontWeight: 600 }}>₹{p.amount} · {p.reason_code.replace(/_/g, ' ')}</div>
          <div style={{ fontSize: 12, color: 'var(--text-muted)', marginTop: 4 }}>Status: {p.status}</div>
          {p.status === 'active' && (
            <>
              <Input placeholder="Dispute reason" value={note} onChange={(e) => setNote(e.target.value)} />
              <Button loading={disputing === p.id} onClick={() => void handleDispute(p.id)}>
                Dispute
              </Button>
            </>
          )}
        </div>
      ))}
    </Screen>
  );
}
