import { useState } from 'react';
import { useNavigate } from 'react-router-dom';
import { Screen } from '../components/Screen';
import { Button } from '../components/Button';
import { Input } from '../components/Input';
import { registerVehicle, getErrorMessage } from '../api';

const CATEGORIES = ['two_wheeler', 'three_wheeler', 'mini_truck', 'pickup_truck', 'large_truck'];

export function VehiclePage() {
  const navigate = useNavigate();
  const [category, setCategory] = useState(CATEGORIES[0]);
  const [plate, setPlate] = useState('');
  const [loading, setLoading] = useState(false);
  const [error, setError] = useState('');
  const [success, setSuccess] = useState('');

  async function handleSubmit() {
    if (!plate.trim()) {
      setError('Enter your vehicle plate number.');
      return;
    }
    setLoading(true);
    setError('');
    try {
      await registerVehicle(category, plate.trim().toUpperCase());
      setSuccess('Vehicle registered successfully.');
    } catch (err) {
      setError(getErrorMessage(err, 'Could not register vehicle.'));
    } finally {
      setLoading(false);
    }
  }

  return (
    <Screen eyebrow="Vehicle" title="Register vehicle" onBack={() => navigate('/profile')}>
      <p style={{ color: 'var(--text-muted)', fontSize: 14, margin: 0 }}>
        You need a registered vehicle before you can receive job offers.
      </p>
      <label style={{ display: 'flex', flexDirection: 'column', gap: 6, fontSize: 13 }}>
        Vehicle type
        <select
          value={category}
          onChange={(e) => setCategory(e.target.value)}
          style={{ border: '1px solid var(--border)', borderRadius: 10, padding: '10px 12px', fontSize: 14 }}
        >
          {CATEGORIES.map((c) => (
            <option key={c} value={c}>
              {c.replace(/_/g, ' ')}
            </option>
          ))}
        </select>
      </label>
      <Input label="Plate number" value={plate} onChange={(e) => setPlate(e.target.value.toUpperCase())} placeholder="KA01AB1234" />
      {error && <p style={{ color: 'var(--danger)', fontSize: 13 }}>{error}</p>}
      {success && <p style={{ color: 'var(--success)', fontSize: 13 }}>{success}</p>}
      <Button loading={loading} onClick={() => void handleSubmit()}>
        Save vehicle
      </Button>
    </Screen>
  );
}
