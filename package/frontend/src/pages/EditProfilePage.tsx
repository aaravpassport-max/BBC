import { useState, useEffect } from 'react';
import { useNavigate } from 'react-router-dom';
import { Screen } from '../components/Screen';
import { Button } from '../components/Button';
import { Input } from '../components/Input';
import { getProfile, updateProfile, getErrorMessage } from '../api';
import { STORAGE_KEYS } from '../constants/brand';

export function EditProfilePage() {
  const navigate = useNavigate();
  const [name, setName] = useState('');
  const [email, setEmail] = useState('');
  const [gstin, setGstin] = useState('');
  const [businessName, setBusinessName] = useState('');
  const [billingAddress, setBillingAddress] = useState('');
  const [phone, setPhone] = useState('');
  const [loading, setLoading] = useState(true);
  const [saving, setSaving] = useState(false);
  const [error, setError] = useState('');
  const [success, setSuccess] = useState('');

  useEffect(() => {
    getProfile()
      .then((p) => {
        setName(p.name || '');
        setEmail(p.email || '');
        setGstin(p.gstin || '');
        setBusinessName(p.business_name || '');
        setBillingAddress(p.billing_address || '');
        setPhone(p.phone);
      })
      .catch((err) => setError(getErrorMessage(err, 'Could not load profile.')))
      .finally(() => setLoading(false));
  }, []);

  async function handleSave() {
    setSaving(true);
    setError('');
    setSuccess('');
    try {
      await updateProfile({
        name: name.trim() || undefined,
        email: email.trim() || null,
        gstin: gstin.trim() || null,
        business_name: businessName.trim() || null,
        billing_address: billingAddress.trim() || null,
      });
      if (name.trim()) {
        localStorage.setItem(STORAGE_KEYS.displayName, name.trim());
      }
      setSuccess('Profile updated.');
    } catch (err) {
      setError(getErrorMessage(err, 'Could not save profile.'));
    } finally {
      setSaving(false);
    }
  }

  return (
    <Screen eyebrow="Account" title="Edit profile" onBack={() => navigate('/profile')}>
      {loading && <p style={{ color: 'var(--text-muted)' }}>Loading…</p>}
      {!loading && (
        <>
          <Input label="Mobile" value={phone ? `+91 ${phone}` : ''} disabled />
          <Input label="Name" value={name} onChange={(e) => setName(e.target.value)} placeholder="Your name" />
          <Input label="Email" type="email" value={email} onChange={(e) => setEmail(e.target.value)} placeholder="you@example.com" />
          <div style={{ marginTop: 16, fontWeight: 600 }}>Business billing (optional)</div>
          <Input label="Business name" value={businessName} onChange={(e) => setBusinessName(e.target.value)} />
          <Input label="GSTIN" value={gstin} onChange={(e) => setGstin(e.target.value.toUpperCase())} placeholder="22AAAAA0000A1Z5" />
          <Input label="Billing address" value={billingAddress} onChange={(e) => setBillingAddress(e.target.value)} placeholder="Full billing address" />
          {error && <p style={{ color: 'var(--danger)', fontSize: 13 }}>{error}</p>}
          {success && <p style={{ color: 'var(--success)', fontSize: 13 }}>{success}</p>}
          <Button loading={saving} onClick={() => void handleSave()}>Save changes</Button>
        </>
      )}
    </Screen>
  );
}
