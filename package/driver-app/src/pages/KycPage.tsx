import { useState, useEffect, useCallback } from 'react';
import { useNavigate } from 'react-router-dom';
import { Camera, CameraResultType, CameraSource } from '@capacitor/camera';
import { Screen } from '../components/Screen';
import { Button } from '../components/Button';
import { Input } from '../components/Input';
import { registerAsDriver, getKycStatus, submitKycStep, getErrorMessage, type KycStatus } from '../api';

const STEP_LABELS: Record<string, string> = {
  personal_details: 'Personal details',
  identity_document: 'Identity document',
  driving_license: 'Driving license',
  vehicle_documents: 'Vehicle documents (RC/insurance)',
  bank_details: 'Bank account details',
  vehicle_photos: 'Vehicle photos',
  consent: 'Background check consent',
};

const DOCUMENT_STEPS = ['identity_document', 'driving_license', 'vehicle_documents', 'bank_details'];

const STATUS_COPY: Record<string, { label: string; tone: string }> = {
  not_submitted: { label: 'Not submitted', tone: 'var(--text-muted)' },
  not_applicable: { label: '—', tone: 'var(--text-muted)' },
  pending_review: { label: 'Under review', tone: 'var(--accent-strong)' },
  approved: { label: 'Approved', tone: 'var(--success)' },
  rejected: { label: 'Rejected', tone: 'var(--danger)' },
};

export function KycPage() {
  const navigate = useNavigate();
  const [status, setStatus] = useState<KycStatus | null>(null);
  const [error, setError] = useState('');
  const [submittingStep, setSubmittingStep] = useState<string | null>(null);
  const [docUrl, setDocUrl] = useState('');

  const refresh = useCallback(async () => {
    try {
      await registerAsDriver(); // idempotent — safe to call every load (PRD 3.1)
      const s = await getKycStatus();
      setStatus(s);
    } catch (err) {
      setError(getErrorMessage(err, 'Could not load your application status.'));
    }
  }, []);

  useEffect(() => {
    void refresh();
  }, [refresh]);

  async function handleSubmitDoc(step: string) {
    if (!docUrl.trim()) {
      setError('Add a document link before submitting.');
      return;
    }
    setSubmittingStep(step);
    setError('');
    try {
      await submitKycStep(step, docUrl.trim());
      setDocUrl('');
      await refresh();
    } catch (err) {
      setError(getErrorMessage(err, 'Could not submit this document.'));
    } finally {
      setSubmittingStep(null);
    }
  }

  // Captures a real photo — on native Android this opens the device camera
  // (via Capacitor's Camera plugin); on web it transparently falls back to
  // a file picker. The backend's document_url field accepts any string
  // (see kyc.routes.ts — no strict URL-format validation), so the
  // resulting base64 data URL submits exactly like a pasted link would.
  async function handleTakePhoto() {
    setError('');
    try {
      const photo = await Camera.getPhoto({
        resultType: CameraResultType.DataUrl,
        source: CameraSource.Camera,
        quality: 80,
      });
      if (photo.dataUrl) {
        setDocUrl(photo.dataUrl);
      }
    } catch {
      // User cancelled the camera — not an error worth surfacing.
    }
  }

  if (!status) {
    return (
      <Screen eyebrow="Onboarding" title="Loading your application…">
        {error && <p style={{ color: 'var(--danger)' }}>{error}</p>}
      </Screen>
    );
  }

  if (status.overall_status === 'approved') {
    return (
      <Screen eyebrow="Onboarding" title="You're approved">
        <p style={{ color: 'var(--text-muted)', fontSize: 15 }}>
          Your documents have been verified. You're ready to start accepting jobs.
        </p>
        <Button onClick={() => navigate('/home')}>Go to Home</Button>
      </Screen>
    );
  }

  return (
    <Screen
      eyebrow="Onboarding"
      title="Verify your documents"
      footer={error ? <p style={{ color: 'var(--danger)', fontSize: 13 }}>{error}</p> : undefined}
    >
      <div
        style={{
          padding: '10px 14px',
          borderRadius: 10,
          background: 'var(--surface)',
          border: '1px solid var(--border)',
          fontSize: 13,
          color:
            status.overall_status === 'rejected'
              ? 'var(--danger)'
              : status.overall_status === 'pending_review'
                ? 'var(--accent-strong)'
                : 'var(--text-muted)',
        }}
      >
        {status.overall_status === 'incomplete' && 'Submit every document below to send your application for review.'}
        {status.overall_status === 'pending_review' && 'All documents submitted — our team is reviewing your application.'}
        {status.overall_status === 'rejected' && 'One or more documents were rejected. Re-submit them below.'}
      </div>

      {status.steps
        .filter((s) => DOCUMENT_STEPS.includes(s.step))
        .map((s) => {
          const copy = STATUS_COPY[s.status] || { label: s.status, tone: 'var(--text-muted)' };
          const needsAction = s.status === 'not_submitted' || s.status === 'rejected';
          return (
            <div
              key={s.step}
              style={{ border: '1px solid var(--border)', borderRadius: 12, padding: 14, background: 'var(--surface)' }}
            >
              <div style={{ display: 'flex', justifyContent: 'space-between', alignItems: 'center' }}>
                <span style={{ fontFamily: 'var(--font-display)', fontWeight: 600, fontSize: 14 }}>
                  {STEP_LABELS[s.step]}
                </span>
                <span style={{ fontSize: 12, fontWeight: 600, color: copy.tone }}>{copy.label}</span>
              </div>
              {s.rejection_reason && (
                <p style={{ fontSize: 12, color: 'var(--danger)', marginTop: 6 }}>Reason: {s.rejection_reason}</p>
              )}
              {needsAction && (
                <div style={{ marginTop: 10 }}>
                  {docUrl.startsWith('data:image') && (
                    <img
                      src={docUrl}
                      alt="Captured document"
                      style={{ width: '100%', maxHeight: 140, objectFit: 'cover', borderRadius: 8, marginBottom: 8 }}
                    />
                  )}
                  <div style={{ display: 'flex', gap: 8 }}>
                    <div style={{ flex: 1 }}>
                      <Input placeholder="Paste document link" value={docUrl} onChange={(e) => setDocUrl(e.target.value)} />
                    </div>
                    <Button variant="ghost" style={{ width: 'auto', padding: '0 14px' }} onClick={handleTakePhoto}>
                      📷 Camera
                    </Button>
                    <Button
                      style={{ width: 'auto', padding: '0 16px' }}
                      loading={submittingStep === s.step}
                      onClick={() => handleSubmitDoc(s.step)}
                    >
                      Submit
                    </Button>
                  </div>
                </div>
              )}
            </div>
          );
        })}
    </Screen>
  );
}
