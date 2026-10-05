import { useState, useEffect, useCallback } from 'react';
import { Layout } from '../components/Layout';
import { Button } from '../components/Button';
import { Input } from '../components/Input';
import { AccessDenied } from '../components/AccessDenied';
import { SkeletonTableRows } from '../components/Skeleton';
import { listBanners, createBanner, publishBanner, ApiError, getErrorMessage, type Banner } from '../api';

function defaultDateRange() {
  const now = new Date();
  const in7Days = new Date(now.getTime() + 7 * 24 * 60 * 60 * 1000);
  return { start: now.toISOString().slice(0, 16), end: in7Days.toISOString().slice(0, 16) };
}

export function MarketingPage() {
  const [banners, setBanners] = useState<Banner[] | null>(null);
  const [forbidden, setForbidden] = useState(false);
  const [error, setError] = useState('');
  const [showForm, setShowForm] = useState(false);
  const [creating, setCreating] = useState(false);
  const defaults = defaultDateRange();
  const [form, setForm] = useState({
    headline: '',
    image_url: '',
    cta_text: '',
    cta_deep_link: 'wallet',
    start_at: defaults.start,
    end_at: defaults.end,
  });

  const refresh = useCallback(async () => {
    try {
      setBanners(await listBanners());
    } catch (err) {
      if (err instanceof ApiError && err.status === 403) setForbidden(true);
      else setError(getErrorMessage(err, 'Could not load banners.'));
    }
  }, []);

  useEffect(() => {
    void refresh();
  }, [refresh]);

  async function handleCreate() {
    setError('');
    if (!form.headline.trim() || !form.image_url.trim() || !form.cta_deep_link.trim()) {
      setError('Headline, image URL, and CTA link are required.');
      return;
    }
    setCreating(true);
    try {
      await createBanner({
        headline: form.headline.trim(),
        image_url: form.image_url.trim(),
        cta_text: form.cta_text.trim() || undefined,
        cta_deep_link: form.cta_deep_link.trim(),
        start_at: new Date(form.start_at).toISOString(),
        end_at: new Date(form.end_at).toISOString(),
      });
      setForm({ ...form, headline: '', image_url: '', cta_text: '' });
      setShowForm(false);
      await refresh();
    } catch (err) {
      setError(getErrorMessage(err, 'Could not create this banner.'));
    } finally {
      setCreating(false);
    }
  }

  async function handlePublish(id: string) {
    setError('');
    try {
      await publishBanner(id);
      await refresh();
    } catch (err) {
      setError(getErrorMessage(err, 'Could not publish this banner.'));
    }
  }

  if (forbidden) {
    return (
      <Layout title="Marketing">
        <AccessDenied />
      </Layout>
    );
  }

  return (
    <Layout
      title="Banners"
      actions={
        <Button style={{ width: 'auto', padding: '0 18px' }} onClick={() => setShowForm((v) => !v)}>
          {showForm ? 'Cancel' : 'New banner'}
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
            gridTemplateColumns: 'repeat(2, 1fr)',
            gap: 12,
          }}
        >
          <Input label="Headline (max 60 chars)" value={form.headline} onChange={(e) => setForm({ ...form, headline: e.target.value })} />
          <Input label="Image URL" value={form.image_url} onChange={(e) => setForm({ ...form, image_url: e.target.value })} />
          <Input label="CTA text (optional)" value={form.cta_text} onChange={(e) => setForm({ ...form, cta_text: e.target.value })} />
          <Input label="CTA deep link" value={form.cta_deep_link} onChange={(e) => setForm({ ...form, cta_deep_link: e.target.value })} />
          <label style={{ display: 'flex', flexDirection: 'column', gap: 6 }}>
            <span style={{ fontSize: 13, color: 'var(--text-muted)' }}>Starts</span>
            <input
              type="datetime-local"
              value={form.start_at}
              onChange={(e) => setForm({ ...form, start_at: e.target.value })}
              style={{ background: 'var(--bg)', border: '1px solid var(--border)', borderRadius: 8, color: 'var(--text)', padding: '10px' }}
            />
          </label>
          <label style={{ display: 'flex', flexDirection: 'column', gap: 6 }}>
            <span style={{ fontSize: 13, color: 'var(--text-muted)' }}>Ends</span>
            <input
              type="datetime-local"
              value={form.end_at}
              onChange={(e) => setForm({ ...form, end_at: e.target.value })}
              style={{ background: 'var(--bg)', border: '1px solid var(--border)', borderRadius: 8, color: 'var(--text)', padding: '10px' }}
            />
          </label>
          <div style={{ gridColumn: 'span 2' }}>
            <Button loading={creating} onClick={handleCreate} style={{ width: 'auto', padding: '0 20px' }}>
              Save as draft
            </Button>
          </div>
        </div>
      )}

      {error && <p style={{ color: 'var(--danger)', fontSize: 13, marginBottom: 16 }}>{error}</p>}

      {banners && banners.length === 0 ? (
        <p style={{ color: 'var(--text-muted)' }}>No banners yet.</p>
      ) : (
        <table>
          <thead>
            <tr>
              <th>Status</th>
              <th>Headline</th>
              <th>CTA</th>
              <th>Window</th>
              <th></th>
            </tr>
          </thead>
          <tbody>
            {banners === null ? (
              <SkeletonTableRows columns={5} rows={3} />
            ) : (
              banners.map((b) => (
              <tr key={b.id}>
                <td>
                  <span
                    style={{
                      fontSize: 12,
                      fontWeight: 600,
                      color: b.status === 'live' ? 'var(--success)' : b.status === 'scheduled' ? 'var(--accent-strong)' : 'var(--text-muted)',
                    }}
                  >
                    {b.status}
                  </span>
                </td>
                <td>{b.headline}</td>
                <td style={{ fontFamily: 'var(--font-mono)', fontSize: 12 }}>{b.cta_deep_link}</td>
                <td style={{ fontSize: 12, color: 'var(--text-muted)' }}>
                  {new Date(b.start_at).toLocaleDateString()} – {new Date(b.end_at).toLocaleDateString()}
                </td>
                <td>
                  {b.status === 'draft' && (
                    <Button
                      style={{ width: 'auto', padding: '4px 14px', minHeight: 32, fontSize: 13 }}
                      onClick={() => handlePublish(b.id)}
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
    </Layout>
  );
}
