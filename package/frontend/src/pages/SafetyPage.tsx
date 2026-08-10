import { useState, useEffect } from 'react';
import { useNavigate } from 'react-router-dom';
import { Screen } from '../components/Screen';
import { Button } from '../components/Button';
import { Input } from '../components/Input';

const TRUSTED_CONTACT_KEY = 'portmystuff_trusted_contact';

interface TrustedContact {
  name: string;
  phone: string;
}

const SAFETY_TIPS = [
  { icon: '📍', title: 'Share trip details', body: 'Use live tracking to share your trip status with family or colleagues.' },
  { icon: '🔐', title: 'Verify pickup OTP', body: 'Only hand over goods after your driver enters the correct pickup code.' },
  { icon: '📞', title: 'Masked calling', body: 'Call your driver through the app — your real number stays private.' },
  { icon: '🆘', title: 'Emergency SOS', body: 'During an active trip, tap SOS on the tracking screen to alert our safety team instantly.' },
  { icon: '⭐', title: 'Rate every trip', body: 'Your ratings help us remove unsafe partners and reward great service.' },
];

export function SafetyPage() {
  const navigate = useNavigate();
  const [contactName, setContactName] = useState('');
  const [contactPhone, setContactPhone] = useState('');
  const [savedContact, setSavedContact] = useState<TrustedContact | null>(null);

  useEffect(() => {
    try {
      const raw = localStorage.getItem(TRUSTED_CONTACT_KEY);
      if (raw) setSavedContact(JSON.parse(raw) as TrustedContact);
    } catch {
      // ignore
    }
  }, []);

  function saveTrustedContact() {
    if (!contactName.trim() || !contactPhone.trim()) return;
    const contact: TrustedContact = { name: contactName.trim(), phone: contactPhone.trim() };
    localStorage.setItem(TRUSTED_CONTACT_KEY, JSON.stringify(contact));
    setSavedContact(contact);
    setContactName('');
    setContactPhone('');
  }

  return (
    <Screen eyebrow="Safety" title="Safety center" onBack={() => navigate('/profile')}>
      <p style={{ fontSize: 14, color: 'var(--text-muted)', marginTop: 0 }}>
        Your safety matters. Here is how PORTMYSTUFF keeps every delivery secure.
      </p>

      <div
        style={{
          border: '1px solid var(--border)',
          borderRadius: 12,
          padding: 16,
          background: 'var(--surface)',
        }}
      >
        <div style={{ fontWeight: 600, fontSize: 15, marginBottom: 8 }}>Trusted contact</div>
        <p style={{ fontSize: 13, color: 'var(--text-muted)', margin: '0 0 12px', lineHeight: 1.5 }}>
          Save a family member or colleague to reference during trips. Stored on this device only — not shared with our servers.
        </p>
        {savedContact && (
          <div
            style={{
              padding: '12px 14px',
              borderRadius: 10,
              background: 'var(--bg)',
              marginBottom: 12,
            }}
          >
            <div style={{ fontWeight: 600 }}>{savedContact.name}</div>
            <div style={{ fontSize: 13, color: 'var(--text-muted)', marginTop: 4 }}>{savedContact.phone}</div>
          </div>
        )}
        <Input placeholder="Contact name" value={contactName} onChange={(e) => setContactName(e.target.value)} />
        <Input
          type="tel"
          placeholder="Contact phone"
          value={contactPhone}
          onChange={(e) => setContactPhone(e.target.value.replace(/\D/g, '').slice(0, 10))}
        />
        <Button onClick={saveTrustedContact}>Save trusted contact</Button>
      </div>

      <div style={{ display: 'flex', flexDirection: 'column', gap: 12 }}>
        {SAFETY_TIPS.map((tip) => (
          <div
            key={tip.title}
            style={{
              border: '1px solid var(--border)',
              borderRadius: 12,
              padding: 16,
              background: 'var(--surface)',
            }}
          >
            <div style={{ fontSize: 20, marginBottom: 6 }}>{tip.icon}</div>
            <div style={{ fontWeight: 600, fontSize: 15 }}>{tip.title}</div>
            <div style={{ fontSize: 13, color: 'var(--text-muted)', marginTop: 4 }}>{tip.body}</div>
          </div>
        ))}
      </div>

      <div
        style={{
          marginTop: 20,
          padding: 16,
          borderRadius: 12,
          background: 'var(--danger-soft, #fff1f0)',
          border: '1px solid var(--danger, #ff4d4f)',
        }}
      >
        <div style={{ fontWeight: 700, color: 'var(--danger)' }}>In an emergency?</div>
        <p style={{ fontSize: 13, color: 'var(--text-muted)', margin: '8px 0 12px' }}>
          If you are on an active trip, open tracking and tap SOS. For immediate danger, call local emergency services (112).
        </p>
        <Button variant="danger" onClick={() => navigate('/history')}>
          Go to my trips
        </Button>
      </div>
    </Screen>
  );
}
