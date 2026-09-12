import { useEffect, useRef } from 'react';
import { MapContainer, TileLayer, Marker, useMap } from 'react-leaflet';
import L from 'leaflet';
import styles from './LiveMap.module.css';

// Leaflet's default marker icons reference image paths that break under
// Vite's bundling (a well-known Leaflet+bundler issue) — replaced here with
// icons styled to match the app's own visual identity instead of Leaflet's
// generic blue pin, which also sidesteps the broken-path problem entirely.
const pickupIcon = new L.DivIcon({
  className: styles.pickupIcon,
  html: '<div class="' + styles.pickupDot + '"></div>',
  iconSize: [18, 18],
  iconAnchor: [9, 9],
});

const driverIcon = new L.DivIcon({
  className: styles.driverIcon,
  html: '<div class="' + styles.driverPin + '">\u{1F69A}</div>',
  iconSize: [36, 36],
  iconAnchor: [18, 18],
});

// Recenters/refits the map whenever the driver's position updates, without
// forcing a full remount of the tile layer (which would flash/reload tiles
// on every 3s poll).
function AutoFit({ points }: { points: [number, number][] }) {
  const map = useMap();
  const hasFitOnce = useRef(false);

  useEffect(() => {
    if (points.length === 0) return;
    if (points.length === 1) {
      map.setView(points[0], map.getZoom() < 13 ? 15 : map.getZoom());
      return;
    }
    // Only auto-fit bounds ONCE per mount, not on every poll tick — after
    // that, let the user pan/zoom freely without the map yanking back.
    if (!hasFitOnce.current) {
      map.fitBounds(points, { padding: [40, 40], maxZoom: 16 });
      hasFitOnce.current = true;
    }
  }, [points, map]);

  return null;
}

export function LiveMap({
  pickup,
  driver,
}: {
  pickup: { lat: number; lng: number } | null;
  driver: { lat: number; lng: number } | null;
}) {
  if (!pickup && !driver) return null;

  const points: [number, number][] = [];
  if (pickup) points.push([pickup.lat, pickup.lng]);
  if (driver) points.push([driver.lat, driver.lng]);
  const center = points[0] || [12.9716, 77.5946];

  return (
    <div className={styles.mapWrap}>
      <MapContainer
        center={center}
        zoom={14}
        scrollWheelZoom={false}
        className={styles.map}
        attributionControl={true}
      >
        <TileLayer
          url="https://{s}.tile.openstreetmap.org/{z}/{x}/{y}.png"
          attribution='&copy; <a href="https://www.openstreetmap.org/copyright">OpenStreetMap</a> contributors'
        />
        {pickup && <Marker position={[pickup.lat, pickup.lng]} icon={pickupIcon} />}
        {driver && <Marker position={[driver.lat, driver.lng]} icon={driverIcon} />}
        <AutoFit points={points} />
      </MapContainer>
    </div>
  );
}
