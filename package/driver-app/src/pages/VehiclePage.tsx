import { useState, useEffect } from 'react';
import { useNavigate } from 'react-router-dom';
import { Screen } from '../components/Screen';
import { Button } from '../components/Button';
import { Input } from '../components/Input';
import { getDriverProfile, registerVehicle, getErrorMessage } from '../api';

const CATEGORIES = ['two_wheeler', 'three_wheeler', 'mini_truck', 'pickup_truck', 'large_truck'];

const CATEGORY_HINTS: Record<string, string> = {
  two_wheeler: 'Small parcels and documents',
  three_wheeler: 'Light goods, short distances',
  mini_truck: 'Furniture, appliances, medium loads',
  pickup_truck: 'Bulky items, construction materials',
  large_truck: 'Heavy commercial freight',
};

function formatCategory(id: string): string {
  return id.replace(/_/g, ' ').replace(/\b\w/g, (c) => c.toUpperCase());
}

export function VehiclePage() {
  const navigate = useNavigate();
  const [category, setCategory] = useState(CATEGORIES[0]);
  const [plate, setPlate] = useState('');
  const [existingVehicle, setExistingVehicle] = useState<{
    plate: string;
    category: string;
    make: string | null;
    model: string | null;
  } | null>(null);
  const [loading, setLoading] = useState(false);
  const [error, setError] = useState('');
  const [success, setSuccess] = useState('');

  useEffect(() => {
    getDriverProfile()
      .then((p) => {
        if (p.vehicle) {
          setExistingVehicle(p.vehicle);
          setPlate(p.vehicle.plate);
          setCategory(p.vehicle.category);
        }
      })
      .catch(() => undefined);
  }, []);

  async function handleSubmit() {
    if (!plate.trim()) {
      setError('Enter your vehicle plate number.');
      return;
    }
    setLoading(true);
    setError('');
    try {
      await registerVehicle(category, plate.trim().toUpperCase());
      setExistingVehicle({ plate: plate.trim().toUpperCase(), category, make: existingVehicle?.make ?? null, model: existingVehicle?.model ?? null });
      setSuccess('Vehicle registered successfully.');
    } catch (err) {
      setError(getErrorMessage(err, 'Could not register vehicle.'));
    } finally {
      setLoading(false);
    }
  }

  return (
    <Screen eyebrow="Vehicle" title="My vehicle" onBack={() => navigate('/profile')}>
      {existingVehicle && (
        <div style={{ border: '1px solid var(--border)', borderRadius: 12, padding: 16, background: 'var(--surface)' }}>
          <div style={{ fontSize: 12, color: 'var(--text-muted)', marginBottom: 6 }}>Registered vehicle</div>
          <div style={{ fontSize: 18, fontWeight: 700, fontFamily: 'var(--font-mono)' }}>{existingVehicle.plate}</div>
          <div style={{ fontSize: 14, marginTop: 4 }}>{formatCategory(existingVehicle.category)}</div>
          {(existingVehicle.make || existingVehicle.model) && (
            <div style={{ fontSize: 13, color: 'var(--text-muted)', marginTop: 4 }}>
              {[existingVehicle.make, existingVehicle.model].filter(Boolean).join(' ')}
            </div>
          )}
        </div>
      )}

      <p style={{ color: 'var(--text-muted)', fontSize: 14, margin: 0 }}>
        Register or update your vehicle to receive job offers matched to your category.
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
              {formatCategory(c)}
            </option>
          ))}
        </select>
      </label>
      {CATEGORY_HINTS[category] && (
        <p style={{ fontSize: 12, color: 'var(--text-muted)', margin: '-4px 0 8px' }}>{CATEGORY_HINTS[category]}</p>
      )}

      <Input label="Plate number" value={plate} onChange={(e) => setPlate(e.target.value.toUpperCase())} placeholder="KA01AB1234" />
      {error && <p style={{ color: 'var(--danger)', fontSize: 13 }}>{error}</p>}
      {success && <p style={{ color: 'var(--success)', fontSize: 13 }}>{success}</p>}
      <Button loading={loading} onClick={() => void handleSubmit()}>
        {existingVehicle ? 'Update vehicle' : 'Save vehicle'}
      </Button>
    </Screen>
  );
}
