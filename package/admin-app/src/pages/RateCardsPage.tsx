import { useState, useEffect, useCallback } from 'react';
import { Layout } from '../components/Layout';
import { Button } from '../components/Button';
import { Input } from '../components/Input';
import { AccessDenied } from '../components/AccessDenied';
import { SkeletonTableRows } from '../components/Skeleton';
import { listRateCards, createRateCard, publishRateCard, updateRateCardSurge, ApiError, getErrorMessage, type RateCard } from '../api';

const CITY_ID = 'f7a78914-17a4-4b8f-91c9-79d76c87c9b9';
const CATEGORY_ID = 'cc0530bc-0866-406f-86cd-2244d997ea9f';

interface RateCardRow extends RateCard {
  vehicle_category_name: string;
  city_name: string;
}

export function RateCardsPage() {
  const [cards, setCards] = useState<RateCardRow[] | null>(null);
  const [forbidden, setForbidden] = useState(false);
  const [error, setError] = useState('');
  const [creating, setCreating] = useState(false);
  const [showForm, setShowForm] = useState(false);
  const [form, setForm] = useState({ base_fare: '', per_km_rate: '', minimum_fare: '', platform_fee: '' });
  const [editingSurgeId, setEditingSurgeId] = useState<string | null>(null);
  const [surgeForm, setSurgeForm] = useState({ tiers: '1.0, 1.2, 1.5, 2.0', cap: '3.0' });
  const [savingSurge, setSavingSurge] = useState(false);

  const refresh = useCallback(async () => {
    try {
      const result = await listRateCards({ cityId: CITY_ID, vehicleCategoryId: CATEGORY_ID });
      setCards(result);
    } catch (err) {
      if (err instanceof ApiError && err.status === 403) setForbidden(true);
      else setError(getErrorMessage(err, 'Could not load rate cards.'));
    }
  }, []);

  useEffect(() => {
    void refresh();
  }, [refresh]);

  async function handleCreate() {
    setError('');
    const base_fare = parseFloat(form.base_fare);
    const per_km_rate = parseFloat(form.per_km_rate);
    const minimum_fare = parseFloat(form.minimum_fare);
    const platform_fee = parseFloat(form.platform_fee || '0');
    if (!base_fare || !per_km_rate || !minimum_fare) {
      setError('Fill in base fare, per-km rate, and minimum fare.');
      return;
    }
    setCreating(true);
    try {
      await createRateCard({
        city_id: CITY_ID,
        vehicle_category_id: CATEGORY_ID,
        base_fare,
        per_km_rate,
        minimum_fare,
        platform_fee,
      });
      setForm({ base_fare: '', per_km_rate: '', minimum_fare: '', platform_fee: '' });
      setShowForm(false);
      await refresh();
    } catch (err) {
      setError(getErrorMessage(err, 'Could not create this rate card.'));
    } finally {
      setCreating(false);
    }
  }

  async function handlePublish(id: string, currentVersion: number) {
    setError('');
    try {
      await publishRateCard(id, currentVersion);
      await refresh();
    } catch (err) {
      if (err instanceof ApiError && err.code === 'VALIDATION_ERROR' && err.details.current_version !== undefined) {
        setError(
          `This card has changed since you loaded it (now at version ${err.details.current_version}). Refreshing.`
        );
        await refresh();
      } else {
        setError(getErrorMessage(err, 'Could not publish this rate card.'));
      }
    }
  }

  function openSurgeEditor(card: RateCardRow) {
    const tiers = Array.isArray(card.surge_tiers) ? card.surge_tiers.join(', ') : '1.0, 1.2, 1.5, 2.0';
    setSurgeForm({ tiers, cap: card.surge_cap ?? '3.0' });
    setEditingSurgeId(card.id);
  }

  async function handleSaveSurge(id: string) {
    setError('');
    const surgeTiers = surgeForm.tiers.split(',').map((t) => parseFloat(t.trim())).filter((n) => !Number.isNaN(n));
    const surgeCap = parseFloat(surgeForm.cap);
    if (surgeTiers.length === 0 || !surgeCap) {
      setError('Enter valid surge tiers (comma-separated) and cap.');
      return;
    }
    setSavingSurge(true);
    try {
      await updateRateCardSurge(id, surgeTiers, surgeCap);
      setEditingSurgeId(null);
      await refresh();
    } catch (err) {
      setError(getErrorMessage(err, 'Could not update surge tiers.'));
    } finally {
      setSavingSurge(false);
    }
  }

  if (forbidden) {
    return (
      <Layout title="Rate Cards">
        <AccessDenied />
      </Layout>
    );
  }

  return (
    <Layout
      title="Rate Cards"
      actions={
        <Button style={{ width: 'auto', padding: '0 18px' }} onClick={() => setShowForm((v) => !v)}>
          {showForm ? 'Cancel' : 'New rate card'}
        </Button>
      }
    >
      {showForm && (
        <div
          style={{
            border: '1px solid var(--border)',
            borderRadius: 12,
            background: 'var(--surface)',
            padding: 20,
            marginBottom: 24,
            display: 'grid',
            gridTemplateColumns: 'repeat(4, 1fr)',
            gap: 12,
            alignItems: 'end',
          }}
        >
          <Input label="Base fare (₹)" value={form.base_fare} onChange={(e) => setForm({ ...form, base_fare: e.target.value })} />
          <Input label="Per-km rate (₹)" value={form.per_km_rate} onChange={(e) => setForm({ ...form, per_km_rate: e.target.value })} />
          <Input label="Minimum fare (₹)" value={form.minimum_fare} onChange={(e) => setForm({ ...form, minimum_fare: e.target.value })} />
          <Input label="Platform fee (₹)" value={form.platform_fee} onChange={(e) => setForm({ ...form, platform_fee: e.target.value })} />
          <div style={{ gridColumn: 'span 4' }}>
            <Button loading={creating} onClick={handleCreate} style={{ width: 'auto', padding: '0 20px' }}>
              Save as draft
            </Button>
          </div>
        </div>
      )}

      {error && <p style={{ color: 'var(--danger)', fontSize: 13, marginBottom: 16 }}>{error}</p>}

      {cards && cards.length === 0 ? (
        <p style={{ color: 'var(--text-muted)' }}>No rate cards yet for this city/category.</p>
      ) : (
        <table>
          <thead>
            <tr>
              <th>Status</th>
              <th>City / Category</th>
              <th>Base fare</th>
              <th>Per km</th>
              <th>Minimum</th>
              <th>Platform fee</th>
              <th>Surge tiers</th>
              <th>Version</th>
              <th></th>
            </tr>
          </thead>
          <tbody>
            {cards === null ? (
              <SkeletonTableRows columns={9} rows={4} />
            ) : (
              cards.map((c) => (
              <tr key={c.id}>
                <td>
                  <span
                    style={{
                      fontSize: 12,
                      fontWeight: 600,
                      color:
                        c.status === 'published'
                          ? 'var(--success)'
                          : c.status === 'superseded'
                            ? 'var(--text-muted)'
                            : 'var(--accent-strong)',
                    }}
                  >
                    {c.status}
                  </span>
                </td>
                <td>
                  {c.city_name} / {c.vehicle_category_name}
                </td>
                <td style={{ fontFamily: 'var(--font-mono)' }}>₹{c.base_fare}</td>
                <td style={{ fontFamily: 'var(--font-mono)' }}>₹{c.per_km_rate}</td>
                <td style={{ fontFamily: 'var(--font-mono)' }}>₹{c.minimum_fare}</td>
                <td style={{ fontFamily: 'var(--font-mono)' }}>₹{c.platform_fee}</td>
                <td style={{ fontSize: 12 }}>
                  {Array.isArray(c.surge_tiers) ? c.surge_tiers.join(' / ') : '—'}
                  {c.surge_cap ? ` (cap ${c.surge_cap}×)` : ''}
                  {c.status !== 'superseded' && (
                    <button
                      type="button"
                      onClick={() => openSurgeEditor(c)}
                      style={{ marginLeft: 8, border: 'none', background: 'none', color: 'var(--accent-strong)', cursor: 'pointer', fontSize: 12 }}
                    >
                      Edit
                    </button>
                  )}
                </td>
                <td style={{ fontFamily: 'var(--font-mono)' }}>{c.version}</td>
                <td>
                  {c.status === 'draft' && (
                    <Button
                      style={{ width: 'auto', padding: '4px 14px', minHeight: 32, fontSize: 13 }}
                      onClick={() => handlePublish(c.id, c.version)}
                    >
                      Publish
                    </Button>
                  )}
                </td>
              </tr>
              ))
            )}
          </tbody>
        </table>
      )}

      {editingSurgeId && (
        <div
          style={{
            position: 'fixed',
            inset: 0,
            background: 'rgba(0,0,0,0.4)',
            display: 'flex',
            alignItems: 'center',
            justifyContent: 'center',
            zIndex: 50,
          }}
          onClick={() => setEditingSurgeId(null)}
        >
          <div
            style={{ background: 'var(--surface)', borderRadius: 12, padding: 24, width: 420, maxWidth: '90vw' }}
            onClick={(e) => e.stopPropagation()}
          >
            <h3 style={{ marginTop: 0 }}>Edit surge tiers</h3>
            <p style={{ fontSize: 13, color: 'var(--text-muted)' }}>Discrete multipliers applied based on live demand/supply ratio.</p>
            <Input label="Tiers (comma-separated)" value={surgeForm.tiers} onChange={(e) => setSurgeForm({ ...surgeForm, tiers: e.target.value })} />
            <Input label="Surge cap (×)" value={surgeForm.cap} onChange={(e) => setSurgeForm({ ...surgeForm, cap: e.target.value })} />
            <div style={{ display: 'flex', gap: 8, marginTop: 16 }}>
              <Button loading={savingSurge} onClick={() => void handleSaveSurge(editingSurgeId)} style={{ width: 'auto' }}>
                Save surge settings
              </Button>
              <Button variant="ghost" onClick={() => setEditingSurgeId(null)} style={{ width: 'auto' }}>
                Cancel
              </Button>
            </div>
          </div>
        </div>
      )}
    </Layout>
  );
}
