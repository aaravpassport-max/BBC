import { useState, useEffect } from 'react';
import { useNavigate } from 'react-router-dom';
import { Screen } from '../components/Screen';
import { Button } from '../components/Button';
import { Input } from '../components/Input';
import { listAddresses, createAddress, deleteAddress, getErrorMessage, type SavedAddress } from '../api';

const LOCATIONS = [
  { label: 'Koramangala Warehouse', lat: 12.952, lng: 77.602 },
  { label: 'Indiranagar Depot', lat: 12.97, lng: 77.62 },
  { label: 'HSR Layout Store', lat: 12.912, lng: 77.638 },
];

export function AddressesPage() {
  const navigate = useNavigate();
  const [addresses, setAddresses] = useState<SavedAddress[]>([]);
  const [label, setLabel] = useState('Home');
  const [addressLine, setAddressLine] = useState('');
  const [locIndex, setLocIndex] = useState(0);
  const [error, setError] = useState('');
  const [loading, setLoading] = useState(false);

  async function refresh() {
    try {
      setAddresses(await listAddresses());
    } catch (err) {
      setError(getErrorMessage(err, 'Could not load addresses.'));
    }
  }

  useEffect(() => {
    void refresh();
  }, []);

  async function handleAdd() {
    if (!addressLine.trim()) {
      setError('Enter an address label.');
      return;
    }
    const loc = LOCATIONS[locIndex];
    setLoading(true);
    setError('');
    try {
      await createAddress({
        label,
        address_line: addressLine.trim(),
        lat: loc.lat,
        lng: loc.lng,
        landmark: null,
        contact_name: null,
        contact_phone: null,
        is_default: addresses.length === 0,
      });
      setAddressLine('');
      await refresh();
    } catch (err) {
      setError(getErrorMessage(err, 'Could not save address.'));
    } finally {
      setLoading(false);
    }
  }

  return (
    <Screen eyebrow="Addresses" title="Saved addresses" onBack={() => navigate('/profile')}>
      {addresses.map((a) => (
        <div
          key={a.id}
          style={{
            border: '1px solid var(--border)',
            borderRadius: 12,
            background: 'var(--surface)',
            padding: 14,
            display: 'flex',
            justifyContent: 'space-between',
            alignItems: 'center',
          }}
        >
          <div>
            <div style={{ fontWeight: 600 }}>{a.label} {a.is_default && <span style={{ fontSize: 11, color: 'var(--accent)' }}>· Default</span>}</div>
            <div style={{ fontSize: 13, color: 'var(--text-muted)' }}>{a.address_line}</div>
          </div>
          <button
            type="button"
            onClick={() => void deleteAddress(a.id).then(refresh)}
            style={{ background: 'none', border: 'none', color: 'var(--danger)', cursor: 'pointer' }}
          >
            Delete
          </button>
        </div>
      ))}

      <div style={{ borderTop: '1px solid var(--border)', paddingTop: 16 }}>
        <div style={{ fontWeight: 600, marginBottom: 10 }}>Add address</div>
        <select
          value={label}
          onChange={(e) => setLabel(e.target.value)}
          style={{ width: '100%', marginBottom: 8, padding: 10, borderRadius: 10, border: '1px solid var(--border)' }}
        >
          <option>Home</option>
          <option>Work</option>
          <option>Other</option>
        </select>
        <Input placeholder="Address name / landmark" value={addressLine} onChange={(e) => setAddressLine(e.target.value)} />
        <select
          value={locIndex}
          onChange={(e) => setLocIndex(Number(e.target.value))}
          style={{ width: '100%', marginBottom: 8, padding: 10, borderRadius: 10, border: '1px solid var(--border)' }}
        >
          {LOCATIONS.map((l, i) => (
            <option key={l.label} value={i}>{l.label}</option>
          ))}
        </select>
        {error && <p style={{ color: 'var(--danger)', fontSize: 13 }}>{error}</p>}
        <Button loading={loading} onClick={() => void handleAdd()}>Save address</Button>
      </div>
    </Screen>
  );
}
