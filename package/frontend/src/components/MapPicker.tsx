import { useState } from 'react';
import { MapContainer, TileLayer, Marker, useMapEvents } from 'react-leaflet';
import L from 'leaflet';
import { Button } from './Button';
import { reverseGeocode } from '../lib/places';
import type { LocationPoint } from '../lib/locations';
import styles from './MapPicker.module.css';

const pinIcon = new L.DivIcon({
  className: '',
  html: '<div style="width:18px;height:18px;border-radius:50%;background:#2b6ce6;border:3px solid white;box-shadow:0 2px 8px rgba(0,0,0,.3)"></div>',
  iconSize: [18, 18],
  iconAnchor: [9, 9],
});

function MapClickHandler({ onPick }: { onPick: (lat: number, lng: number) => void }) {
  useMapEvents({
    click(e) {
      onPick(e.latlng.lat, e.latlng.lng);
    },
  });
  return null;
}

interface MapPickerProps {
  initial?: { lat: number; lng: number };
  onConfirm: (loc: LocationPoint) => void;
  onClose: () => void;
}

export function MapPicker({ initial, onConfirm, onClose }: MapPickerProps) {
  const [pin, setPin] = useState(initial ?? { lat: 12.9716, lng: 77.5946 });
  const [label, setLabel] = useState('Pinned location');
  const [loading, setLoading] = useState(false);

  async function handleConfirm() {
    setLoading(true);
    try {
      const resolved = await reverseGeocode(pin.lat, pin.lng);
      onConfirm({ label: resolved, lat: pin.lat, lng: pin.lng, addressLine: resolved });
    } finally {
      setLoading(false);
    }
  }

  return (
    <div className={styles.overlay} onClick={onClose}>
      <div className={styles.sheet} onClick={(e) => e.stopPropagation()}>
        <h3 className={styles.title}>Pick location on map</h3>
        <p className={styles.hint}>Tap anywhere on the map to set your pin.</p>
        <div className={styles.map}>
          <MapContainer center={[pin.lat, pin.lng]} zoom={14} style={{ height: '100%', width: '100%' }}>
            <TileLayer url="https://{s}.tile.openstreetmap.org/{z}/{x}/{y}.png" />
            <Marker position={[pin.lat, pin.lng]} icon={pinIcon} />
            <MapClickHandler
              onPick={(lat, lng) => {
                setPin({ lat, lng });
                setLabel('Pinned location');
              }}
            />
          </MapContainer>
        </div>
        <div style={{ fontSize: 13, color: 'var(--text-muted)' }}>{label}</div>
        <Button loading={loading} onClick={() => void handleConfirm()}>
          Confirm location
        </Button>
        <Button variant="ghost" onClick={onClose}>
          Cancel
        </Button>
      </div>
    </div>
  );
}
