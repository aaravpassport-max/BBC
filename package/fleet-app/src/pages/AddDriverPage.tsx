import { useState, type FormEvent } from 'react';
import { useNavigate } from 'react-router-dom';
import { Screen } from '../components/Screen';
import { Input } from '../components/Input';
import { Button } from '../components/Button';
import { addDriverToFleet, getErrorMessage } from '../api';

export function AddDriverPage() {
  const navigate = useNavigate();
  const [phone, setPhone] = useState('');
  const [loading, setLoading] = useState(false);
  const [error, setError] = useState('');

  async function handleSubmit(e: FormEvent) {
    e.preventDefault();
    setError('');
    if (!/^[0-9]{10}$/.test(phone)) {
      setError('Enter a valid 10-digit mobile number.');
      return;
    }
    setLoading(true);
    try {
      await addDriverToFleet(phone);
      navigate('/home');
    } catch (err) {
      setError(getErrorMessage(err, 'Could not add this driver.'));
    } finally {
      setLoading(false);
    }
  }

  return (
    <Screen eyebrow="Fleet" title="Add a driver">
      <Button variant="ghost" style={{ width: 'auto', padding: '4px 0', marginBottom: 4 }} onClick={() => navigate(-1)}>
        ← Back
      </Button>
      <p style={{ color: 'var(--text-muted)', fontSize: 14, lineHeight: 1.6 }}>
        The driver must already have their own account on the driver app — enter the mobile number they registered
        with. They'll be linked to your fleet immediately; a driver already in another fleet can't be added here.
      </p>
      <form onSubmit={handleSubmit} style={{ display: 'flex', flexDirection: 'column', gap: 16 }}>
        <Input
          label="Driver's mobile number"
          type="tel"
          inputMode="numeric"
          prefix="+91"
          placeholder="98765 43210"
          value={phone}
          maxLength={10}
          onChange={(e) => setPhone(e.target.value.replace(/\D/g, ''))}
          error={error}
          autoFocus
        />
        <Button type="submit" loading={loading}>
          Add to fleet
        </Button>
      </form>
    </Screen>
  );
}
