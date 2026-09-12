import { useState, useEffect, useCallback } from 'react';
import { useNavigate } from 'react-router-dom';
import { Camera, CameraResultType, CameraSource } from '@capacitor/camera';
import { Screen } from '../components/Screen';
import { Button } from '../components/Button';
import { Input } from '../components/Input';
import { registerAsDriver, getKycStatus, submitKycStep, getErrorMessage, type KycStatus } from '../api';

const STEP_LABELS: Record<string, string> = {
  personal_details: 'Personal details',
  identity_document: 'Identity document (Aadhaar/PAN)',
  driving_license: 'Driving license',
  vehicle_documents: 'Vehicle documents (RC/insurance)',
  bank_details: 'Bank account details',
  vehicle_photos: 'Vehicle photos',
  consent: 'Background check consent',
};

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
  const [stepData, setStepData] = useState<Record<string, string>>({});
  const [personal, setPersonal] = useState({ fullName: '', pan: '', address: '' });
  const [bank, setBank] = useState({ account: '', ifsc: '', holder: '' });
  const [consent, setConsent] = useState(false);

  const refresh = useCallback(async () => {
    try {
      await registerAsDriver();
      const s = await getKycStatus();
      setStatus(s);
    } catch (err) {
      setError(getErrorMessage(err, 'Could not load your application status.'));
    }
  }, []);

  useEffect(() => {
    void refresh();
  }, [refresh]);

  async function submit(step: string, payload: string) {
    setSubmittingStep(step);
    setError('');
    try {
      await submitKycStep(step, payload);
      setStepData((prev) => ({ ...prev, [step]: '' }));
      await refresh();
    } catch (err) {
      setError(getErrorMessage(err, 'Could not submit this step.'));
    } finally {
      setSubmittingStep(null);
    }
  }

  async function handleTakePhoto(step: string) {
    try {
      const photo = await Camera.getPhoto({ resultType: CameraResultType.DataUrl, source: CameraSource.Camera, quality: 80 });
      if (photo.dataUrl) setStepData((prev) => ({ ...prev, [step]: photo.dataUrl! }));
    } catch {
      // cancelled
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
        <p style={{ color: 'var(--text-muted)', fontSize: 15 }}>Your documents have been verified. You're ready to start accepting jobs.</p>
        <Button onClick={() => navigate('/home')}>Go to Home</Button>
      </Screen>
    );
  }

  return (
    <Screen eyebrow="Onboarding" title="Complete your profile" footer={error ? <p style={{ color: 'var(--danger)', fontSize: 13 }}>{error}</p> : undefined}>
      <div style={{ padding: '10px 14px', borderRadius: 10, background: 'var(--surface)', border: '1px solid var(--border)', fontSize: 13, color: 'var(--text-muted)' }}>
        {status.overall_status === 'incomplete' && 'Complete every step below to send your application for review.'}
        {status.overall_status === 'pending_review' && 'All steps submitted — our team is reviewing your application.'}
        {status.overall_status === 'rejected' && 'One or more steps were rejected. Re-submit them below.'}
      </div>

      {status.steps.map((s) => {
        const copy = STATUS_COPY[s.status] || { label: s.status, tone: 'var(--text-muted)' };
        const needsAction = s.status === 'not_submitted' || s.status === 'rejected';
        const value = stepData[s.step] || '';

        return (
          <div key={s.step} style={{ border: '1px solid var(--border)', borderRadius: 12, padding: 14, background: 'var(--surface)' }}>
            <div style={{ display: 'flex', justifyContent: 'space-between', alignItems: 'center' }}>
              <span style={{ fontWeight: 600, fontSize: 14 }}>{STEP_LABELS[s.step] || s.step}</span>
              <span style={{ fontSize: 12, fontWeight: 600, color: copy.tone }}>{copy.label}</span>
            </div>
            {s.rejection_reason && <p style={{ fontSize: 12, color: 'var(--danger)', marginTop: 6 }}>Reason: {s.rejection_reason}</p>}

            {needsAction && s.step === 'personal_details' && (
              <div style={{ marginTop: 10, display: 'flex', flexDirection: 'column', gap: 8 }}>
                <Input placeholder="Full name" value={personal.fullName} onChange={(e) => setPersonal({ ...personal, fullName: e.target.value })} />
                <Input placeholder="PAN number" value={personal.pan} onChange={(e) => setPersonal({ ...personal, pan: e.target.value.toUpperCase() })} />
                <Input placeholder="Residential address" value={personal.address} onChange={(e) => setPersonal({ ...personal, address: e.target.value })} />
                <Button loading={submittingStep === s.step} onClick={() => void submit(s.step, JSON.stringify(personal))}>Save details</Button>
              </div>
            )}

            {needsAction && s.step === 'bank_details' && (
              <div style={{ marginTop: 10, display: 'flex', flexDirection: 'column', gap: 8 }}>
                <Input placeholder="Account holder name" value={bank.holder} onChange={(e) => setBank({ ...bank, holder: e.target.value })} />
                <Input placeholder="Account number" value={bank.account} onChange={(e) => setBank({ ...bank, account: e.target.value })} />
                <Input placeholder="IFSC code" value={bank.ifsc} onChange={(e) => setBank({ ...bank, ifsc: e.target.value.toUpperCase() })} />
                <Button loading={submittingStep === s.step} onClick={() => void submit(s.step, JSON.stringify(bank))}>Save bank details</Button>
              </div>
            )}

            {needsAction && s.step === 'consent' && (
              <div style={{ marginTop: 10 }}>
                <label style={{ display: 'flex', gap: 10, fontSize: 13, alignItems: 'flex-start' }}>
                  <input type="checkbox" checked={consent} onChange={(e) => setConsent(e.target.checked)} />
                  I consent to background verification and agree to PORTMYSTUFF Partner terms.
                </label>
                <Button style={{ marginTop: 10 }} loading={submittingStep === s.step} disabled={!consent} onClick={() => void submit(s.step, 'consent_granted')}>
                  Submit consent
                </Button>
              </div>
            )}

            {needsAction && !['personal_details', 'bank_details', 'consent'].includes(s.step) && (
              <div style={{ marginTop: 10 }}>
                {value.startsWith('data:image') && (
                  <img src={value} alt="" style={{ width: '100%', maxHeight: 140, objectFit: 'cover', borderRadius: 8, marginBottom: 8 }} />
                )}
                <div style={{ display: 'flex', gap: 8 }}>
                  <div style={{ flex: 1 }}>
                    <Input placeholder="Paste document link or use camera" value={value} onChange={(e) => setStepData((prev) => ({ ...prev, [s.step]: e.target.value }))} />
                  </div>
                  <Button variant="ghost" style={{ width: 'auto', padding: '0 14px' }} onClick={() => void handleTakePhoto(s.step)}>📷</Button>
                  <Button style={{ width: 'auto', padding: '0 16px' }} loading={submittingStep === s.step} onClick={() => void submit(s.step, value.trim())}>Submit</Button>
                </div>
              </div>
            )}
          </div>
        );
      })}
    </Screen>
  );
}
