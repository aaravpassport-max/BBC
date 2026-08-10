import { useState, type CSSProperties, type ReactNode } from 'react';
import { useNavigate } from 'react-router-dom';
import { Geolocation } from '@capacitor/geolocation';
import { Screen } from '../components/Screen';
import { Button } from '../components/Button';
import { getQuote, getErrorMessage, type Quote } from '../api';

// Preset locations within the backend's seeded Bengaluru service zone —
// a real deployment would use a maps autocomplete + reverse-geocode here
// (PRD 2.2.4); this reference frontend uses a fixed set of real, working
// coordinates so "get a quote" actually resolves against the live backend
// rather than requiring a Places API integration out of scope for this pass.
const LOCATIONS = [
  { label: 'Koramangala Warehouse', lat: 12.952, lng: 77.602 },
  { label: 'Indiranagar Depot', lat: 12.97, lng: 77.62 },
  { label: 'HSR Layout Store', lat: 12.912, lng: 77.638 },
  { label: 'Whitefield Yard', lat: 12.969, lng: 77.75 },
];

// Display metadata only — icon/blurb per category for the selection UI.
// The actual price, availability, and category set are always the real
// backend response; this table exists purely so five real categories
// don't render as five identical rows with only a name differing.
const CATEGORY_DISPLAY: Record<string, { icon: string; blurb: string }> = {
  two_wheeler: { icon: '🛵', blurb: 'Small parcels, up to 20kg' },
  three_wheeler: { icon: '🛺', blurb: 'Up to 500kg' },
  mini_truck: { icon: '🚚', blurb: 'Up to 750kg' },
  pickup_truck: { icon: '🚛', blurb: 'Up to 1500kg' },
  large_truck: { icon: '🚛', blurb: 'Up to 5000kg' },
};

export function HomePage() {
  const navigate = useNavigate();
  const [pickupIndex, setPickupIndex] = useState(0);
  const [dropIndex, setDropIndex] = useState(1);
  const [goodsCategory, setGoodsCategory] = useState('Furniture');
  const [helperNeeded, setHelperNeeded] = useState(false);
  const [loading, setLoading] = useState(false);
  const [error, setError] = useState('');
  const [locating, setLocating] = useState(false);
  // A device-GPS pickup point overrides the LOCATIONS preset when set — kept
  // separate rather than mutating LOCATIONS, since the real coordinate isn't
  // one of the fixed demo points and has no natural "index" into that list.
  const [devicePickup, setDevicePickup] = useState<{ lat: number; lng: number; label: string } | null>(null);
  const [quotes, setQuotes] = useState<Quote[] | null>(null);
  const [choosingVehicle, setChoosingVehicle] = useState(false);
  // P1 gap-analysis item — scheduled (future-dated) bookings. Empty string
  // means "now" (instant booking); a real, backend-validated datetime
  // means "book for later" — the backend enforces the actual lead-time and
  // advance-window rules, this is just the picker.
  const [scheduledFor, setScheduledFor] = useState('');

  // The datetime-local input needs a value at least MIN_LEAD_MINUTES from
  // now as its floor, matching the backend's own real validation — shown
  // here so a user isn't allowed to pick a time the server will reject.
  const minScheduleValue = new Date(Date.now() + 35 * 60 * 1000).toISOString().slice(0, 16);
  const maxScheduleValue = new Date(Date.now() + 6.5 * 24 * 60 * 60 * 1000).toISOString().slice(0, 16);

  // Uses the real device location. On Android (via Capacitor) this prompts
  // for the native location permission and returns real GPS coordinates; on
  // web it transparently delegates to the browser's navigator.geolocation —
  // same call, same UI, no platform branching needed in this component.
  async function handleUseMyLocation() {
    setError('');
    setLocating(true);
    try {
      const permission = await Geolocation.requestPermissions();
      if (permission.location !== 'granted' && permission.coarseLocation !== 'granted') {
        setError('Location permission was denied. Choose a pickup point from the list instead.');
        return;
      }
      const position = await Geolocation.getCurrentPosition({ enableHighAccuracy: true, timeout: 10000 });
      setDevicePickup({
        lat: position.coords.latitude,
        lng: position.coords.longitude,
        label: 'Current location',
      });
    } catch {
      setError('Could not get your current location. Choose a pickup point from the list instead.');
    } finally {
      setLocating(false);
    }
  }

  async function handleGetQuote() {
    setError('');
    const pickup = devicePickup ?? LOCATIONS[pickupIndex];
    const drop = LOCATIONS[dropIndex];
    if (!devicePickup && pickupIndex === dropIndex) {
      setError('Pickup and drop cannot be the same location.');
      return;
    }
    setLoading(true);
    try {
      const res = await getQuote({
        pickup: { lat: pickup.lat, lng: pickup.lng },
        drops: [{ lat: drop.lat, lng: drop.lng }],
        // vehicle_category deliberately omitted — returns every published
        // category for this route (PRD gap-analysis P1 item: the backend
        // has always supported this; only one real category ever existed
        // to return).
      });
      if (res.quotes.length === 0) {
        setError('No vehicles available for this route right now.');
        return;
      }
      setQuotes(res.quotes);
      setChoosingVehicle(true);
    } catch (err) {
      // A real device location that falls outside the seeded service zone
      // surfaces here as an ordinary API error (out-of-service-area) — the
      // same path already handles it, no special-casing needed.
      setError(getErrorMessage(err, 'Could not get a quote. Please try again.'));
    } finally {
      setLoading(false);
    }
  }

  function handleChooseVehicle(quote: Quote) {
    const pickup = devicePickup ?? LOCATIONS[pickupIndex];
    const drop = LOCATIONS[dropIndex];
    navigate('/confirm', {
      state: {
        quote,
        pickupLabel: pickup.label,
        dropLabel: drop.label,
        goodsCategory,
        helperNeeded,
        scheduledFor: scheduledFor ? new Date(scheduledFor).toISOString() : undefined,
      },
    });
  }

  return (
    <Screen eyebrow="New booking" title="Where's it headed?">
      <div style={{ display: 'flex', gap: 8, alignSelf: 'flex-end' }}>
        <Button variant="ghost" style={{ width: 'auto', padding: '6px 14px' }} onClick={() => navigate('/history')}>
          🧾 History
        </Button>
        <Button variant="ghost" style={{ width: 'auto', padding: '6px 14px' }} onClick={() => navigate('/wallet')}>
          💰 Wallet
        </Button>
      </div>
      <Field label="Pickup">
        {devicePickup ? (
          <div style={{ display: 'flex', alignItems: 'center', justifyContent: 'space-between', gap: 8 }}>
            <span style={{ fontSize: 15 }}>📍 {devicePickup.label}</span>
            <button
              onClick={() => setDevicePickup(null)}
              style={{ background: 'none', border: 'none', color: 'var(--accent-strong)', fontSize: 13, cursor: 'pointer' }}
            >
              Change
            </button>
          </div>
        ) : (
          <>
            <select value={pickupIndex} onChange={(e) => setPickupIndex(Number(e.target.value))} style={selectStyle}>
              {LOCATIONS.map((loc, i) => (
                <option key={loc.label} value={i}>
                  {loc.label}
                </option>
              ))}
            </select>
            <button
              onClick={handleUseMyLocation}
              disabled={locating}
              style={{
                background: 'none',
                border: 'none',
                color: 'var(--accent-strong)',
                fontSize: 13,
                cursor: 'pointer',
                marginTop: 6,
                padding: 0,
              }}
            >
              {locating ? 'Getting your location…' : '📍 Use my current location'}
            </button>
          </>
        )}
      </Field>

      <Field label="Drop">
        <select value={dropIndex} onChange={(e) => setDropIndex(Number(e.target.value))} style={selectStyle}>
          {LOCATIONS.map((loc, i) => (
            <option key={loc.label} value={i}>
              {loc.label}
            </option>
          ))}
        </select>
      </Field>

      <Field label="What's being moved?">
        <select value={goodsCategory} onChange={(e) => setGoodsCategory(e.target.value)} style={selectStyle}>
          <option>Furniture</option>
          <option>Electronics</option>
          <option>Boxes</option>
          <option>Other</option>
        </select>
      </Field>

      <label
        style={{
          display: 'flex',
          alignItems: 'center',
          gap: 10,
          fontSize: 14,
          color: 'var(--text-muted)',
          padding: '4px 2px',
        }}
      >
        <input type="checkbox" checked={helperNeeded} onChange={(e) => setHelperNeeded(e.target.checked)} />
        Need a helper to load/unload
      </label>

      <Field label="When">
        <div style={{ display: 'flex', gap: 8, marginBottom: scheduledFor ? 8 : 0 }}>
          <button
            onClick={() => setScheduledFor('')}
            style={{
              flex: 1,
              padding: '10px 0',
              borderRadius: 10,
              border: `1px solid ${scheduledFor ? 'var(--border)' : 'var(--accent)'}`,
              background: scheduledFor ? 'transparent' : 'var(--accent)',
              color: scheduledFor ? 'var(--text)' : 'var(--accent-ink)',
              fontWeight: 600,
              cursor: 'pointer',
            }}
          >
            Now
          </button>
          <button
            onClick={() => setScheduledFor((v) => v || minScheduleValue)}
            style={{
              flex: 1,
              padding: '10px 0',
              borderRadius: 10,
              border: `1px solid ${scheduledFor ? 'var(--accent)' : 'var(--border)'}`,
              background: scheduledFor ? 'var(--accent)' : 'transparent',
              color: scheduledFor ? 'var(--accent-ink)' : 'var(--text)',
              fontWeight: 600,
              cursor: 'pointer',
            }}
          >
            Schedule for later
          </button>
        </div>
        {scheduledFor && (
          <input
            type="datetime-local"
            value={scheduledFor}
            min={minScheduleValue}
            max={maxScheduleValue}
            onChange={(e) => setScheduledFor(e.target.value)}
            style={selectStyle}
          />
        )}
      </Field>

      {error && <p style={{ color: 'var(--danger)', fontSize: 13 }}>{error}</p>}

      <Button onClick={handleGetQuote} loading={loading}>
        See prices
      </Button>

      {choosingVehicle && quotes && (
        <div style={{ display: 'flex', flexDirection: 'column', gap: 10, marginTop: 8 }}>
          <div style={{ display: 'flex', justifyContent: 'space-between', alignItems: 'center' }}>
            <h2 style={{ fontSize: 15 }}>Choose a vehicle</h2>
            <button
              onClick={() => setChoosingVehicle(false)}
              style={{ background: 'none', border: 'none', color: 'var(--text-muted)', fontSize: 13, cursor: 'pointer' }}
            >
              Edit trip
            </button>
          </div>
          {quotes
            .slice()
            .sort((a, b) => a.fare_breakdown.final_fare - b.fare_breakdown.final_fare)
            .map((q) => {
              const display = CATEGORY_DISPLAY[q.vehicle_category] ?? { icon: '🚐', blurb: '' };
              return (
                <button
                  key={q.quote_id}
                  onClick={() => handleChooseVehicle(q)}
                  style={{
                    display: 'flex',
                    alignItems: 'center',
                    justifyContent: 'space-between',
                    gap: 12,
                    width: '100%',
                    textAlign: 'left',
                    border: '1px solid var(--border)',
                    borderRadius: 12,
                    background: 'var(--surface)',
                    padding: '14px 16px',
                    cursor: 'pointer',
                    color: 'var(--text)',
                  }}
                >
                  <div style={{ display: 'flex', alignItems: 'center', gap: 12 }}>
                    <span style={{ fontSize: 26 }}>{display.icon}</span>
                    <div>
                      <div style={{ fontFamily: 'var(--font-display)', fontWeight: 700, fontSize: 14, textTransform: 'capitalize' }}>
                        {q.vehicle_category.replace(/_/g, ' ')}
                      </div>
                      <div style={{ color: 'var(--text-muted)', fontSize: 12 }}>{display.blurb}</div>
                    </div>
                  </div>
                  <div style={{ fontFamily: 'var(--font-mono)', fontWeight: 700, fontSize: 16, color: 'var(--accent-strong)' }}>
                    ₹{q.fare_breakdown.final_fare.toFixed(2)}
                  </div>
                </button>
              );
            })}
        </div>
      )}
    </Screen>
  );
}

function Field({ label, children }: { label: string; children: ReactNode }) {
  return (
    <div style={{ display: 'flex', flexDirection: 'column', gap: 6 }}>
      <span style={{ fontSize: 13, color: 'var(--text-muted)', fontWeight: 500 }}>{label}</span>
      {children}
    </div>
  );
}

const selectStyle: CSSProperties = {
  background: 'var(--surface)',
  border: '1px solid var(--border)',
  borderRadius: 10,
  color: 'var(--text)',
  padding: '14px',
  fontSize: 15,
  fontFamily: 'var(--font-body)',
  outline: 'none',
};
