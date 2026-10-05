import { useState, useEffect, useCallback } from 'react';
import { Layout } from '../components/Layout';
import { Button } from '../components/Button';
import { AccessDenied } from '../components/AccessDenied';
import { listPendingKyc, reviewKycDocument, ApiError, getErrorMessage } from '../api';
import { SkeletonTableRows } from '../components/Skeleton';

interface PendingKycDoc {
  id: string;
  driver_id: string;
  doc_type: string;
  status: string;
  created_at: string;
  phone: string;
  name: string | null;
}

export function KycReviewPage() {
  const [docs, setDocs] = useState<PendingKycDoc[] | null>(null);
  const [forbidden, setForbidden] = useState(false);
  const [error, setError] = useState('');
  const [actingOn, setActingOn] = useState<string | null>(null);

  const refresh = useCallback(async () => {
    try {
      setDocs(await listPendingKyc());
    } catch (err) {
      if (err instanceof ApiError && err.status === 403) setForbidden(true);
      else setError(getErrorMessage(err, 'Could not load KYC queue.'));
    }
  }, []);

  useEffect(() => {
    void refresh();
  }, [refresh]);

  async function handleReview(documentId: string, decision: 'approved' | 'rejected') {
    setActingOn(documentId);
    setError('');
    try {
      await reviewKycDocument(documentId, decision, decision === 'rejected' ? 'DOC_BLURRY' : undefined);
      await refresh();
    } catch (err) {
      setError(getErrorMessage(err, 'Could not review this document.'));
    } finally {
      setActingOn(null);
    }
  }

  if (forbidden) {
    return (
      <Layout title="KYC Review">
        <AccessDenied />
      </Layout>
    );
  }

  return (
    <Layout title="KYC Review">
      {error && <p style={{ color: 'var(--danger)', fontSize: 13, marginBottom: 16 }}>{error}</p>}
      {docs === null && !error && <SkeletonTableRows columns={5} rows={4} />}
      {docs && docs.length === 0 && <p style={{ color: 'var(--text-muted)' }}>No documents pending review.</p>}
      {docs && docs.length > 0 && (
        <table>
          <thead>
            <tr>
              <th>Driver</th>
              <th>Document</th>
              <th>Submitted</th>
              <th>Actions</th>
            </tr>
          </thead>
          <tbody>
            {docs.map((d) => (
              <tr key={d.id}>
                <td>
                  <div>{d.name || '—'}</div>
                  <div style={{ fontSize: 12, color: 'var(--text-muted)' }}>+91 {d.phone}</div>
                </td>
                <td>{d.doc_type.replace(/_/g, ' ')}</td>
                <td>{new Date(d.created_at).toLocaleString()}</td>
                <td style={{ display: 'flex', gap: 8 }}>
                  <Button
                    style={{ width: 'auto', padding: '6px 14px', minHeight: 32, fontSize: 13 }}
                    loading={actingOn === d.id}
                    onClick={() => void handleReview(d.id, 'approved')}
                  >
                    Approve
                  </Button>
                  <Button
                    variant="ghost"
                    style={{ width: 'auto', padding: '6px 14px', minHeight: 32, fontSize: 13, color: 'var(--danger)' }}
                    loading={actingOn === d.id}
                    onClick={() => void handleReview(d.id, 'rejected')}
                  >
                    Reject
                  </Button>
                </td>
              </tr>
            ))}
          </tbody>
        </table>
      )}
    </Layout>
  );
}
