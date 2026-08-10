import { useState, useEffect } from 'react';
import { useNavigate } from 'react-router-dom';
import { Screen } from '../components/Screen';
import { Button } from '../components/Button';
import { Input } from '../components/Input';
import { getDriverProfile, updateDriverProfile, getErrorMessage } from '../api';

export function EditProfilePage() {
  const navigate = useNavigate();
  const [name, setName] = useState('');
  const [email, setEmail] = useState('');
  const [phone, setPhone] = useState('');
  const [rating, setRating] = useState<number | null>(null);
  const [ratingCount, setRatingCount] = useState(0);
  const [loading, setLoading] = useState(false);
  const [error, setError] = useState('');
  const [success, setSuccess] = useState('');

  useEffect(() => {
    getDriverProfile()
      .then((p) => {
        setName(p.name || '');
        setEmail(p.email || '');
        setPhone(p.phone);
        setRating(p.rating_avg);
        setRatingCount(p.rating_count);
      })
      .catch((err) => setError(getErrorMessage(err, 'Could not load profile.')));
  }, []);

  async function handleSave() {
    setError('');
    setSuccess('');
    setLoading(true);
    try {
      await updateDriverProfile({
        name: name.trim() || undefined,
        email: email.trim() ? email.trim() : null,
      });
      setSuccess('Profile updated.');
    } catch (err) {
      setError(getErrorMessage(err, 'Could not save profile.'));
    } finally {
      setLoading(false);
    }
  }

  return (
    <Screen eyebrow="Account" title="Edit profile" onBack={() => navigate('/profile')}>
      {rating != null && (
        <div
          style={{
            border: '1px solid var(--border)',
            borderRadius: 12,
            padding: 14,
            background: 'var(--surface)',
            marginBottom: 4,
          }}
        >
          <div style={{ fontSize: 12, color: 'var(--text-muted)' }}>Partner stats</div>
          <div style={{ fontSize: 20, fontWeight: 700, marginTop: 4 }}>
            ★ {rating.toFixed(1)} <span style={{ fontSize: 14, fontWeight: 400, color: 'var(--text-muted)' }}>({ratingCount} ratings)</span>
          </div>
        </div>
      )}

      <Input label="Name" value={name} onChange={(e) => setName(e.target.value)} placeholder="Your name" />
      <Input label="Phone" value={phone} disabled placeholder="Your phone" />
      <p style={{ fontSize: 12, color: 'var(--text-muted)', margin: '-6px 0 8px' }}>
        Phone number cannot be changed here. Contact support if you need to update it.
      </p>
      <Input label="Email" type="email" value={email} onChange={(e) => setEmail(e.target.value)} placeholder="you@example.com" />
      {error && <p style={{ color: 'var(--danger)', fontSize: 13 }}>{error}</p>}
      {success && <p style={{ color: 'var(--success)', fontSize: 13 }}>{success}</p>}
      <Button loading={loading} onClick={() => void handleSave()}>
        Save changes
      </Button>
    </Screen>
  );
}
