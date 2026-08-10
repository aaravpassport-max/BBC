import { useState, useEffect, useCallback } from 'react';
import { useNavigate } from 'react-router-dom';
import { Screen } from '../components/Screen';
import { Button } from '../components/Button';
import { getFleetVehicles, getFleetDrivers, reassignVehicle, getErrorMessage, type FleetVehicle, type FleetDriver } from '../api';
import { SkeletonRowList } from '../components/Skeleton';

export function VehiclesPage() {
  const navigate = useNavigate();
  const [vehicles, setVehicles] = useState<FleetVehicle[] | null>(null);
  const [drivers, setDrivers] = useState<FleetDriver[]>([]);
  const [error, setError] = useState('');
  const [success, setSuccess] = useState('');
  const [reassigningId, setReassigningId] = useState<string | null>(null);

  const refresh = useCallback(async () => {
    try {
      const [v, d] = await Promise.all([getFleetVehicles(), getFleetDrivers()]);
      setVehicles(v);
      setDrivers(d);
    } catch (err) {
      setError(getErrorMessage(err, 'Could not load your vehicles.'));
    }
  }, []);

  useEffect(() => {
    void refresh();
  }, [refresh]);

  async function handleReassign(vehicleId: string, newDriverId: string) {
    setError('');
    setSuccess('');
    setReassigningId(vehicleId);
    try {
      const result = await reassignVehicle(vehicleId, newDriverId);
      setSuccess(
        result.effective === 'immediate'
          ? 'Reassigned immediately.'
          : 'This vehicle is mid-trip — reassignment will apply automatically once that trip completes.'
      );
      await refresh();
    } catch (err) {
      setError(getErrorMessage(err, 'Could not reassign this vehicle.'));
    } finally {
      setReassigningId(null);
    }
  }

  return (
    <Screen eyebrow="Fleet" title="Vehicles">
      <Button variant="ghost" style={{ width: 'auto', padding: '4px 0', marginBottom: 4 }} onClick={() => navigate(-1)}>
        ← Back
      </Button>

      {error && <p style={{ color: 'var(--danger)', fontSize: 13 }}>{error}</p>}
      {success && <p style={{ color: 'var(--success)', fontSize: 13 }}>{success}</p>}

      {vehicles === null && !error && <SkeletonRowList count={2} />}

      {vehicles && vehicles.length === 0 && (
        <p style={{ color: 'var(--text-muted)', fontSize: 14 }}>No vehicles in your fleet yet.</p>
      )}

      {vehicles && vehicles.length > 0 && (
        <div style={{ display: 'flex', flexDirection: 'column', gap: 10 }}>
          {vehicles.map((v) => (
            <div
              key={v.id}
              style={{
                border: '1px solid var(--border)',
                borderRadius: 12,
                background: 'var(--surface)',
                padding: '14px 16px',
              }}
            >
              <div style={{ display: 'flex', justifyContent: 'space-between', alignItems: 'center' }}>
                <div>
                  <div style={{ fontFamily: 'var(--font-mono)', fontWeight: 700, fontSize: 15 }}>{v.plate_number}</div>
                  <div style={{ fontSize: 12, color: 'var(--text-muted)', textTransform: 'capitalize' }}>
                    {v.category.replace(/_/g, ' ')}
                  </div>
                </div>
                {v.scheduled_reassignment_to && (
                  <span style={{ fontSize: 11, color: 'var(--accent-strong)' }}>Reassignment pending</span>
                )}
              </div>
              <div style={{ marginTop: 10, display: 'flex', gap: 8, alignItems: 'center' }}>
                <span style={{ fontSize: 13, color: 'var(--text-muted)' }}>Driver:</span>
                <select
                  value={v.driver_id || ''}
                  disabled={reassigningId === v.id}
                  onChange={(e) => e.target.value && handleReassign(v.id, e.target.value)}
                  style={{
                    flex: 1,
                    background: 'var(--bg)',
                    border: '1px solid var(--border)',
                    borderRadius: 8,
                    color: 'var(--text)',
                    padding: '8px 10px',
                    fontSize: 13,
                  }}
                >
                  <option value="" disabled>
                    Unassigned
                  </option>
                  {drivers.map((d) => (
                    <option key={d.driver_id} value={d.driver_id}>
                      +91 {d.phone}
                    </option>
                  ))}
                </select>
              </div>
            </div>
          ))}
        </div>
      )}
    </Screen>
  );
}
