import { useState, useEffect } from 'react';
import { useNavigate } from 'react-router-dom';
import { Screen } from '../components/Screen';
import { Button } from '../components/Button';
import { LocationPicker } from '../components/LocationPicker';
import { MapPicker } from '../components/MapPicker';
import { listAddresses, createAddress, updateAddress, deleteAddress, getErrorMessage, type SavedAddress } from '../api';
import { PRESET_LOCATIONS, type LocationPoint } from '../lib/locations';

export function AddressesPage() {
  const navigate = useNavigate();
  const [addresses, setAddresses] = useState<SavedAddress[]>([]);
  const [label, setLabel] = useState('Home');
  const [location, setLocation] = useState<LocationPoint | null>(PRESET_LOCATIONS[0]);
  const [addressLine, setAddressLine] = useState('');
  const [contactName, setContactName] = useState('');
  const [contactPhone, setContactPhone] = useState('');
  const [mapOpen, setMapOpen] = useState(false);
  const [editingId, setEditingId] = useState<string | null>(null);
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

  function startEdit(a: SavedAddress) {
    setEditingId(a.id);
    setLabel(a.label);
    setAddressLine(a.address_line);
    setContactName(a.contact_name ?? '');
    setContactPhone(a.contact_phone ?? '');
    setLocation({ label: a.label, lat: a.lat, lng: a.lng, addressLine: a.address_line });
  }

  function resetForm() {
    setEditingId(null);
    setLabel('Home');
    setAddressLine('');
    setContactName('');
    setContactPhone('');
    setLocation(PRESET_LOCATIONS[0]);
  }

  async function handleSave() {
    if (!location || !addressLine.trim()) {
      setError('Enter address details and pick a location.');
      return;
    }
    setLoading(true);
    setError('');
    try {
      const payload = {
        label,
        address_line: addressLine.trim(),
        lat: location.lat,
        lng: location.lng,
        landmark: location.addressLine || null,
        contact_name: contactName.trim() || null,
        contact_phone: contactPhone.trim() || null,
        is_default: addresses.length === 0,
      };
      if (editingId) {
        await updateAddress(editingId, payload);
      } else {
        await createAddress(payload);
      }
      resetForm();
      await refresh();
    } catch (err) {
      setError(getErrorMessage(err, 'Could not save address.'));
    } finally {
      setLoading(false);
    }
  }

  async function setDefault(id: string) {
    const addr = addresses.find((a) => a.id === id);
    if (!addr) return;
    await updateAddress(id, { is_default: true });
    await refresh();
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
          }}
        >
          <div style={{ display: 'flex', justifyContent: 'space-between', gap: 8 }}>
            <div>
              <div style={{ fontWeight: 600 }}>
                {a.label}{' '}
                {a.is_default && <span style={{ fontSize: 11, color: 'var(--accent)' }}>· Default</span>}
              </div>
              <div style={{ fontSize: 13, color: 'var(--text-muted)' }}>{a.address_line}</div>
              {(a.contact_name || a.contact_phone) && (
                <div style={{ fontSize: 12, color: 'var(--text-muted)', marginTop: 4 }}>
                  {a.contact_name}{a.contact_name && a.contact_phone ? ' · ' : ''}{a.contact_phone}
                </div>
              )}
            </div>
            <div style={{ display: 'flex', flexDirection: 'column', gap: 4 }}>
              <button type="button" onClick={() => startEdit(a)} style={{ background: 'none', border: 'none', color: 'var(--accent)', cursor: 'pointer', fontSize: 12 }}>
                Edit
              </button>
              {!a.is_default && (
                <button type="button" onClick={() => void setDefault(a.id)} style={{ background: 'none', border: 'none', color: 'var(--text-muted)', cursor: 'pointer', fontSize: 12 }}>
                  Set default
                </button>
              )}
              <button type="button" onClick={() => void deleteAddress(a.id).then(refresh)} style={{ background: 'none', border: 'none', color: 'var(--danger)', cursor: 'pointer', fontSize: 12 }}>
                Delete
              </button>
            </div>
          </div>
        </div>
      ))}

      <div style={{ borderTop: '1px solid var(--border)', paddingTop: 16 }}>
        <div style={{ fontWeight: 600, marginBottom: 10 }}>{editingId ? 'Edit address' : 'Add address'}</div>
        <select
          value={label}
          onChange={(e) => setLabel(e.target.value)}
          style={{ width: '100%', marginBottom: 8, padding: 10, borderRadius: 10, border: '1px solid var(--border)' }}
        >
          <option>Home</option>
          <option>Work</option>
          <option>Other</option>
        </select>
        <input
          placeholder="Flat / building / landmark"
          value={addressLine}
          onChange={(e) => setAddressLine(e.target.value)}
          style={{ width: '100%', marginBottom: 8, padding: 10, borderRadius: 10, border: '1px solid var(--border)' }}
        />
        <input
          placeholder="Contact name (optional)"
          value={contactName}
          onChange={(e) => setContactName(e.target.value)}
          style={{ width: '100%', marginBottom: 8, padding: 10, borderRadius: 10, border: '1px solid var(--border)' }}
        />
        <input
          placeholder="Contact phone (optional)"
          type="tel"
          value={contactPhone}
          onChange={(e) => setContactPhone(e.target.value.replace(/\D/g, '').slice(0, 10))}
          style={{ width: '100%', marginBottom: 8, padding: 10, borderRadius: 10, border: '1px solid var(--border)' }}
        />
        <LocationPicker value={location} onChange={setLocation} onPickOnMap={() => setMapOpen(true)} />
        {error && <p style={{ color: 'var(--danger)', fontSize: 13 }}>{error}</p>}
        <div style={{ display: 'flex', gap: 8, marginTop: 8 }}>
          <Button loading={loading} onClick={() => void handleSave()}>{editingId ? 'Update' : 'Save address'}</Button>
          {editingId && <Button variant="ghost" onClick={resetForm}>Cancel</Button>}
        </div>
      </div>

      {mapOpen && (
        <MapPicker
          initial={location ?? undefined}
          onConfirm={(loc) => {
            setLocation(loc);
            setMapOpen(false);
          }}
          onClose={() => setMapOpen(false)}
        />
      )}
    </Screen>
  );
}
