import { useEffect, useRef } from 'react';
import { MapContainer, TileLayer, Marker, Polyline, CircleMarker, useMap } from 'react-leaflet';
import L from 'leaflet';
import styles from './LiveMap.module.css';

const pickupIcon = new L.DivIcon({
  className: styles.pickupIcon,
  html: '<div class="' + styles.pickupDot + '"></div>',
  iconSize: [18, 18],
  iconAnchor: [9, 9],
});

const dropIcon = new L.DivIcon({
  className: styles.dropIcon,
  html: '<div class="' + styles.dropDot + '"></div>',
  iconSize: [18, 18],
  iconAnchor: [9, 9],
});

const driverIcon = new L.DivIcon({
  className: styles.driverIcon,
  html: '<div class="' + styles.driverPin + '">\u{1F69A}</div>',
  iconSize: [36, 36],
  iconAnchor: [18, 18],
});

function AutoFit({ points }: { points: [number, number][] }) {
  const map = useMap();
  const hasFitOnce = useRef(false);

  useEffect(() => {
    if (points.length === 0) return;
    if (points.length === 1) {
      map.setView(points[0], map.getZoom() < 13 ? 15 : map.getZoom());
      return;
    }
    if (!hasFitOnce.current) {
      map.fitBounds(points, { padding: [40, 40], maxZoom: 16 });
      hasFitOnce.current = true;
    }
  }, [points, map]);

  return null;
}

export function LiveMap({
  pickup,
  drops = [],
  driver,
  routePoints,
  heatCells = [],
}: {
  pickup: { lat: number; lng: number } | null;
  drops?: Array<{ lat: number; lng: number }>;
  driver: { lat: number; lng: number } | null;
  routePoints?: Array<{ lat: number; lng: number }>;
  heatCells?: Array<{ lat: number; lng: number; demand_score: number; surge_zone?: boolean }>;
}) {
  if (!pickup && !driver && drops.length === 0 && heatCells.length === 0) return null;

  const points: [number, number][] = [];
  if (pickup) points.push([pickup.lat, pickup.lng]);
  for (const d of drops) points.push([d.lat, d.lng]);
  if (driver) points.push([driver.lat, driver.lng]);

  const fallbackRoute: [number, number][] = [];
  if (pickup) fallbackRoute.push([pickup.lat, pickup.lng]);
  for (const d of drops) fallbackRoute.push([d.lat, d.lng]);

  const polyline: [number, number][] =
    routePoints && routePoints.length >= 2
      ? routePoints.map((p) => [p.lat, p.lng] as [number, number])
      : fallbackRoute;

  const center = points[0] || (heatCells[0] ? [heatCells[0].lat, heatCells[0].lng] : [12.9716, 77.5946]);

  return (
    <div className={styles.mapWrap}>
      <MapContainer center={center as [number, number]} zoom={14} scrollWheelZoom={false} className={styles.map} attributionControl={true}>
        <TileLayer
          url="https://{s}.tile.openstreetmap.org/{z}/{x}/{y}.png"
          attribution='&copy; <a href="https://www.openstreetmap.org/copyright">OpenStreetMap</a> contributors'
        />
        {heatCells.map((cell, i) => (
          <CircleMarker
            key={`heat-${i}-${cell.lat}`}
            center={[cell.lat, cell.lng]}
            radius={12 + cell.demand_score * 18}
            pathOptions={{
              color: cell.surge_zone ? '#e67e22' : '#e74c3c',
              fillColor: cell.surge_zone ? '#f39c12' : '#e74c3c',
              fillOpacity: 0.25 + cell.demand_score * 0.45,
              weight: 1,
            }}
          />
        ))}
        {pickup && <Marker position={[pickup.lat, pickup.lng]} icon={pickupIcon} />}
        {drops.map((d, i) => (
          <Marker key={`drop-${i}-${d.lat}`} position={[d.lat, d.lng]} icon={dropIcon} />
        ))}
        {driver && <Marker position={[driver.lat, driver.lng]} icon={driverIcon} />}
        {polyline.length >= 2 && (
          <Polyline
            positions={polyline}
            pathOptions={{
              color: routePoints ? '#1a5fd4' : '#2b6ce6',
              weight: routePoints ? 5 : 4,
              opacity: routePoints ? 0.85 : 0.7,
              dashArray: routePoints ? undefined : '8 6',
            }}
          />
        )}
        <AutoFit points={points.length > 0 ? points : heatCells.map((c) => [c.lat, c.lng] as [number, number])} />
      </MapContainer>
    </div>
  );
}
