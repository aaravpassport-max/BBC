import { useEffect, useState } from 'react';
import { useNavigate } from 'react-router-dom';
import { Camera, CameraResultType, CameraSource } from '@capacitor/camera';
import { Screen } from '../components/Screen';
import { Button } from '../components/Button';
import { Skeleton } from '../components/Skeleton';
import { listDriverDocuments, uploadKycDocument, submitKycStep, getErrorMessage, type DriverDocument } from '../api';

const DOC_LABELS: Record<string, string> = {
  identity: 'Identity document',
  driving_license: 'Driving license',
  rc: 'Vehicle RC',
  bank_details: 'Bank details',
  insurance: 'Insurance',
  permit: 'Permit',
  puc: 'PUC certificate',
};

function statusColor(status: string): string {
  if (status === 'approved') return 'var(--success)';
  if (status === 'rejected') return 'var(--danger)';
  if (status === 'pending_review') return 'var(--accent)';
  return 'var(--text-muted)';
}

export function DocumentsPage() {
  const navigate = useNavigate();
  const [docs, setDocs] = useState<DriverDocument[]>([]);
  const [loading, setLoading] = useState(true);
  const [error, setError] = useState('');
  const [uploading, setUploading] = useState<string | null>(null);

  const refresh = () => {
    setLoading(true);
    listDriverDocuments()
      .then((r) => setDocs(r.items))
      .catch((err) => setError(getErrorMessage(err, 'Could not load documents.')))
      .finally(() => setLoading(false));
  };

  useEffect(() => {
    refresh();
  }, []);

  async function handleReupload(docType: string) {
    setUploading(docType);
    setError('');
    try {
      const photo = await Camera.getPhoto({
        quality: 80,
        resultType: CameraResultType.DataUrl,
        source: CameraSource.Camera,
        allowEditing: false,
      });
      if (!photo.dataUrl) throw new Error('No photo captured');
      const uploaded = await uploadKycDocument(photo.dataUrl);
      const step =
        docType === 'driving_license'
          ? 'driving_license'
          : docType === 'rc'
            ? 'vehicle_documents'
            : docType === 'identity'
              ? 'identity_document'
              : 'vehicle_documents';
      await submitKycStep(step, uploaded.url);
      refresh();
    } catch (err) {
      setError(getErrorMessage(err, 'Could not upload document.'));
    } finally {
      setUploading(null);
    }
  }

  return (
    <Screen eyebrow="Compliance" title="Document center" withNav>
      <p style={{ fontSize: 13, color: 'var(--text-muted)', marginBottom: 12 }}>
        Keep all documents valid to stay eligible for jobs. Expired documents automatically suspend your account.
      </p>

      {loading ? (
        <Skeleton width="100%" height={80} radius={12} />
      ) : docs.length === 0 ? (
        <p style={{ fontSize: 14, color: 'var(--text-muted)' }}>No documents on file yet. Complete KYC onboarding first.</p>
      ) : (
        docs.map((doc) => {
          const expiring =
            doc.days_until_expiry != null && doc.days_until_expiry <= 30 && doc.days_until_expiry > 0;
          const expired = doc.days_until_expiry != null && doc.days_until_expiry <= 0;
          return (
            <div
              key={doc.id}
              style={{
                border: '1px solid var(--border)',
                borderRadius: 12,
                padding: 14,
                background: 'var(--surface)',
                marginBottom: 10,
              }}
            >
              <div style={{ display: 'flex', justifyContent: 'space-between', alignItems: 'flex-start', gap: 8 }}>
                <div>
                  <div style={{ fontWeight: 600, fontSize: 14 }}>{DOC_LABELS[doc.doc_type] || doc.doc_type}</div>
                  <div style={{ fontSize: 12, color: statusColor(doc.status), marginTop: 4, textTransform: 'capitalize' }}>
                    {doc.status.replace(/_/g, ' ')}
                  </div>
                  {doc.expiry_date && (
                    <div style={{ fontSize: 12, color: expired ? 'var(--danger)' : expiring ? '#e67e22' : 'var(--text-muted)', marginTop: 4 }}>
                      {expired ? 'Expired' : expiring ? `Expires in ${doc.days_until_expiry} days` : `Valid until ${doc.expiry_date}`}
                    </div>
                  )}
                  {doc.rejection_reason && (
                    <div style={{ fontSize: 12, color: 'var(--danger)', marginTop: 4 }}>Reason: {doc.rejection_reason}</div>
                  )}
                </div>
                {(doc.status === 'rejected' || expired || expiring) && (
                  <Button
                    variant="ghost"
                    loading={uploading === doc.doc_type}
                    onClick={() => void handleReupload(doc.doc_type)}
                  >
                    Re-upload
                  </Button>
                )}
              </div>
            </div>
          );
        })
      )}

      <Button variant="ghost" onClick={() => navigate('/kyc')}>
        Go to KYC wizard
      </Button>

      {error && <p style={{ color: 'var(--danger)', fontSize: 13, marginTop: 12 }}>{error}</p>}
    </Screen>
  );
}
