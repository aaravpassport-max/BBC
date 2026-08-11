import { useEffect, useRef, useState } from 'react';
import { Geolocation } from '@capacitor/geolocation';
import type { LocationPoint } from '../lib/locations';
import { searchPlaces, type PlaceResult } from '../lib/places';
import type { SavedAddress } from '../api/profile';
import styles from './LocationPicker.module.css';

interface LocationPickerProps {
  value: LocationPoint | null;
  onChange: (loc: LocationPoint) => void;
  placeholder?: string;
  savedAddresses?: SavedAddress[];
  onPickOnMap?: () => void;
  /** Bias online search toward this coordinate (e.g. pickup GPS or current location). */
  searchBias?: { lat: number; lng: number };
}

export function LocationPicker({
  value,
  onChange,
  placeholder = 'Search address or landmark',
  savedAddresses = [],
  onPickOnMap,
  searchBias,
}: LocationPickerProps) {
  const [editing, setEditing] = useState(!value);
  const [query, setQuery] = useState('');
  const [suggestions, setSuggestions] = useState<PlaceResult[]>([]);
  const [loading, setLoading] = useState(false);
  const [locating, setLocating] = useState(false);
  const debounceRef = useRef<ReturnType<typeof setTimeout> | null>(null);

  useEffect(() => {
    if (!editing) return;
    if (debounceRef.current) clearTimeout(debounceRef.current);
    debounceRef.current = setTimeout(() => {
      setLoading(true);
      void searchPlaces(query, searchBias)
        .then(setSuggestions)
        .finally(() => setLoading(false));
    }, query.length > 0 ? 300 : 0);
    return () => {
      if (debounceRef.current) clearTimeout(debounceRef.current);
    };
  }, [query, editing, searchBias?.lat, searchBias?.lng]);

  function selectPlace(place: PlaceResult) {
    const saved = savedAddresses.find((a) => a.id === place.id);
    onChange({
      label: place.label,
      lat: place.lat,
      lng: place.lng,
      addressLine: place.addressLine,
      contactName: saved?.contact_name ?? undefined,
      contactPhone: saved?.contact_phone ?? undefined,
      unitDetail: saved?.landmark ?? undefined,
    });
    setEditing(false);
    setQuery('');
    setSuggestions([]);
  }

  async function captureCurrentLocation() {
    setLocating(true);
    try {
      const permission = await Geolocation.requestPermissions();
      if (permission.location !== 'granted' && permission.coarseLocation !== 'granted') return;
      const position = await Geolocation.getCurrentPosition({ enableHighAccuracy: true, timeout: 10000 });
      onChange({
        label: 'Current location',
        lat: position.coords.latitude,
        lng: position.coords.longitude,
      });
      setEditing(false);
    } finally {
      setLocating(false);
    }
  }

  const savedResults: PlaceResult[] = savedAddresses.map((a) => ({
    id: a.id,
    label: a.label,
    addressLine: a.address_line,
    lat: a.lat,
    lng: a.lng,
    source: 'saved',
  }));

  const displaySuggestions = query.trim()
    ? suggestions
    : [...savedResults, ...suggestions.filter((s) => s.source === 'preset')];

  if (!editing && value) {
    return (
      <div className={styles.selected}>
        <div>
          <div className={styles.selectedLabel}>{value.label}</div>
          {value.addressLine && <div className={styles.selectedSub}>{value.addressLine}</div>}
        </div>
        <button type="button" className={styles.changeBtn} onClick={() => setEditing(true)}>
          Change
        </button>
      </div>
    );
  }

  return (
    <div className={styles.wrap}>
      <input
        className={styles.input}
        value={query}
        onChange={(e) => setQuery(e.target.value)}
        placeholder={placeholder}
        autoFocus
      />
      <div className={styles.actions}>
        <button type="button" className={styles.actionLink} onClick={() => void captureCurrentLocation()} disabled={locating}>
          {locating ? 'Getting location…' : '📍 Use current location'}
        </button>
        {onPickOnMap && (
          <button type="button" className={styles.actionLink} onClick={onPickOnMap}>
            🗺️ Pick on map
          </button>
        )}
        {value && (
          <button type="button" className={styles.actionLink} onClick={() => setEditing(false)}>
            Cancel
          </button>
        )}
      </div>
      {(displaySuggestions.length > 0 || loading) && (
        <div className={styles.suggestions}>
          {loading && displaySuggestions.length === 0 && (
            <div className={styles.suggestion} style={{ color: 'var(--text-muted)' }}>
              Searching…
            </div>
          )}
          {displaySuggestions.map((place) => (
            <button
              key={`${place.source}-${place.id ?? place.label}-${place.lat}`}
              type="button"
              className={styles.suggestion}
              onClick={() => selectPlace(place)}
            >
              <div className={styles.suggestionTitle}>
                {place.label}{' '}
                {place.source === 'saved' && <span className={styles.badge}>Saved</span>}
              </div>
              {place.addressLine && <div className={styles.suggestionSub}>{place.addressLine}</div>}
            </button>
          ))}
        </div>
      )}
    </div>
  );
}
