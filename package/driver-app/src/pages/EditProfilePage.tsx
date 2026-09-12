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
  const [loading, setLoading] = useState(false);
  const [error, setError] = useState('');
  const [success, setSuccess] = useState('');

  useEffect(() => {
    getDriverProfile()
      .then((p) => {
        setName(p.name || '');
        setEmail(p.email || '');
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
      <Input label="Name" value={name} onChange={(e) => setName(e.target.value)} placeholder="Your name" />
      <Input label="Email" type="email" value={email} onChange={(e) => setEmail(e.target.value)} placeholder="you@example.com" />
      {error && <p style={{ color: 'var(--danger)', fontSize: 13 }}>{error}</p>}
      {success && <p style={{ color: 'var(--success)', fontSize: 13 }}>{success}</p>}
      <Button loading={loading} onClick={() => void handleSave()}>
        Save changes
      </Button>
    </Screen>
  );
}
