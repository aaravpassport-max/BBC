import { useState, useEffect, useCallback } from 'react';
import { Layout } from '../components/Layout';
import { AccessDenied } from '../components/AccessDenied';
import { Skeleton } from '../components/Skeleton';
import {
  getRevenueDashboard,
  getBookingFunnel,
  getCancellationBreakdown,
  getDriverUtilization,
  getOfflineReasonAnalytics,
  listSurgeZones,
  ApiError, getErrorMessage,
  type RevenueDashboard,
  type BookingFunnel,
  type CancellationBreakdown,
  type DriverUtilizationRow,
  type OfflineReasonAnalytics,
  type SurgeZone,
} from '../api';

function money(n: number): string {
  return `₹${n.toLocaleString('en-IN', { minimumFractionDigits: 2, maximumFractionDigits: 2 })}`;
}

export function AnalyticsPage() {
  const [revenue, setRevenue] = useState<RevenueDashboard | null>(null);
  const [funnel, setFunnel] = useState<BookingFunnel | null>(null);
  const [cancellations, setCancellations] = useState<CancellationBreakdown | null>(null);
  const [utilization, setUtilization] = useState<DriverUtilizationRow[] | null>(null);
  const [offline, setOffline] = useState<OfflineReasonAnalytics | null>(null);
  const [surgeZones, setSurgeZones] = useState<SurgeZone[] | null>(null);
  const [forbidden, setForbidden] = useState(false);
  const [error, setError] = useState('');

  const refresh = useCallback(async () => {
    try {
      const [r, f, c, u, offlineData, zones] = await Promise.all([
        getRevenueDashboard(),
        getBookingFunnel(),
        getCancellationBreakdown(),
        getDriverUtilization(),
        getOfflineReasonAnalytics(7).catch(() => null),
        listSurgeZones().catch(() => []),
      ]);
      setRevenue(r);
      setFunnel(f);
      setCancellations(c);
      setUtilization(u);
      setOffline(offlineData);
      setSurgeZones(zones);
    } catch (err) {
      if (err instanceof ApiError && err.status === 403) setForbidden(true);
      else setError(getErrorMessage(err, 'Could not load analytics.'));
    }
  }, []);

  useEffect(() => {
    void refresh();
  }, [refresh]);

  if (forbidden) {
    return (
      <Layout title="Analytics">
        <AccessDenied />
      </Layout>
    );
  }

  return (
    <Layout title="Analytics">
      {error && <p style={{ color: 'var(--danger)', fontSize: 13, marginBottom: 16 }}>{error}</p>}

      {!revenue || !funnel || !cancellations || !utilization ? (
        <div style={{ display: 'grid', gridTemplateColumns: 'repeat(4, 1fr)', gap: 14 }}>
          {Array.from({ length: 4 }).map((_, i) => (
            <div key={i} style={{ border: '1px solid var(--border)', borderRadius: 12, background: 'var(--surface)', padding: 16 }}>
              <Skeleton width={90} height={11} style={{ marginBottom: 10 }} />
              <Skeleton width={60} height={22} />
            </div>
          ))}
        </div>
      ) : (
        <>
          <div style={{ display: 'grid', gridTemplateColumns: 'repeat(4, 1fr)', gap: 14, marginBottom: 32 }}>
            <MetricCard label="Gross revenue" value={money(revenue.gross_revenue)} />
            <MetricCard label="Platform fee revenue" value={money(revenue.platform_fee_revenue)} accent />
            <MetricCard label="Take rate" value={`${revenue.take_rate_pct}%`} />
            <MetricCard label="Completed bookings" value={String(revenue.completed_bookings)} />
          </div>

          <h2 style={{ fontSize: 16, marginBottom: 14 }}>Booking funnel</h2>
          <div style={{ display: 'flex', gap: 0, marginBottom: 8 }}>
            {funnel.stages.map((stage, i) => (
              <div key={stage.stage} style={{ flex: 1, display: 'flex', alignItems: 'center' }}>
                <div
                  style={{
                    border: '1px solid var(--border)',
                    borderRadius: 12,
                    background: 'var(--surface)',
                    padding: '16px 18px',
                    flex: 1,
                  }}
                >
                  <div style={{ fontSize: 12, color: 'var(--text-muted)', textTransform: 'capitalize', marginBottom: 4 }}>
                    {stage.stage.replace(/_/g, ' ')}
                  </div>
                  <div style={{ fontFamily: 'var(--font-mono)', fontSize: 22, fontWeight: 700 }}>{stage.count}</div>
                  {i > 0 && (
                    <div style={{ fontSize: 11, color: 'var(--text-muted)', marginTop: 2 }}>
                      {stage.conversion_from_previous_pct}% conversion
                    </div>
                  )}
                </div>
                {i < funnel.stages.length - 1 && (
                  <div style={{ color: 'var(--border)', fontSize: 20, padding: '0 8px' }}>→</div>
                )}
              </div>
            ))}
          </div>
          <p style={{ fontSize: 12, color: 'var(--text-muted)', marginBottom: 36 }}>
            {funnel.no_drivers_found} bookings found no available drivers · {funnel.cancelled} cancelled
          </p>

          <div style={{ display: 'grid', gridTemplateColumns: '1fr 1fr', gap: 32 }}>
            <div>
              <h2 style={{ fontSize: 16, marginBottom: 14 }}>
                Cancellations · {cancellations.cancellation_rate_pct}% of {cancellations.total_bookings} bookings
              </h2>
              {cancellations.by_reason.length === 0 ? (
                <p style={{ color: 'var(--text-muted)', fontSize: 13 }}>No cancellations in range.</p>
              ) : (
                <table>
                  <thead>
                    <tr>
                      <th>Reason</th>
                      <th>Count</th>
                    </tr>
                  </thead>
                  <tbody>
                    {cancellations.by_reason.map((r) => (
                      <tr key={r.reason_code || 'unspecified'}>
                        <td style={{ fontFamily: 'var(--font-mono)', fontSize: 13 }}>{r.reason_code || 'unspecified'}</td>
                        <td style={{ fontFamily: 'var(--font-mono)' }}>{r.count}</td>
                      </tr>
                    ))}
                  </tbody>
                </table>
              )}
            </div>

            <div>
              <h2 style={{ fontSize: 16, marginBottom: 4 }}>Driver utilization</h2>
              <p style={{ fontSize: 11, color: 'var(--text-muted)', marginBottom: 10 }}>
                Trip-hours proxy (not true online-hours — see the backend README)
              </p>
              {utilization.length === 0 ? (
                <p style={{ color: 'var(--text-muted)', fontSize: 13 }}>No completed trips in range.</p>
              ) : (
                <table>
                  <thead>
                    <tr>
                      <th>Driver</th>
                      <th>Trips</th>
                      <th>Trip hours</th>
                    </tr>
                  </thead>
                  <tbody>
                    {utilization.slice(0, 10).map((u) => (
                      <tr key={u.driver_id}>
                        <td style={{ fontFamily: 'var(--font-mono)', fontSize: 12 }}>{u.driver_id.slice(0, 8)}</td>
                        <td style={{ fontFamily: 'var(--font-mono)' }}>{u.completed_trips}</td>
                        <td style={{ fontFamily: 'var(--font-mono)' }}>{u.trip_hours}</td>
                      </tr>
                    ))}
                  </tbody>
                </table>
              )}
            </div>
          </div>

          <div style={{ display: 'grid', gridTemplateColumns: '1fr 1fr', gap: 32, marginTop: 32 }}>
            <div>
              <h2 style={{ fontSize: 16, marginBottom: 14 }}>
                Driver offline reasons · last {offline?.period_days ?? 7} days
              </h2>
              {!offline || offline.by_reason.length === 0 ? (
                <p style={{ color: 'var(--text-muted)', fontSize: 13 }}>No offline events recorded in range.</p>
              ) : (
                <table>
                  <thead>
                    <tr>
                      <th>Reason</th>
                      <th>Events</th>
                    </tr>
                  </thead>
                  <tbody>
                    {offline.by_reason.map((r) => (
                      <tr key={r.reason_code}>
                        <td style={{ fontFamily: 'var(--font-mono)', fontSize: 13 }}>{r.reason_code}</td>
                        <td style={{ fontFamily: 'var(--font-mono)' }}>{r.event_count}</td>
                      </tr>
                    ))}
                  </tbody>
                </table>
              )}
            </div>

            <div>
              <h2 style={{ fontSize: 16, marginBottom: 14 }}>Surge zones</h2>
              {!surgeZones || surgeZones.length === 0 ? (
                <p style={{ color: 'var(--text-muted)', fontSize: 13 }}>No surge zones configured.</p>
              ) : (
                <table>
                  <thead>
                    <tr>
                      <th>Zone</th>
                      <th>City</th>
                      <th>Version</th>
                    </tr>
                  </thead>
                  <tbody>
                    {surgeZones.map((z) => (
                      <tr key={z.id}>
                        <td>{z.name}</td>
                        <td>{z.city_name}</td>
                        <td style={{ fontFamily: 'var(--font-mono)' }}>{z.version}</td>
                      </tr>
                    ))}
                  </tbody>
                </table>
              )}
            </div>
          </div>
        </>
      )}
    </Layout>
  );
}

function MetricCard({ label, value, accent }: { label: string; value: string; accent?: boolean }) {
  return (
    <div style={{ border: '1px solid var(--border)', borderRadius: 12, background: 'var(--surface)', padding: 18 }}>
      <div style={{ fontSize: 12, color: 'var(--text-muted)', marginBottom: 8 }}>{label}</div>
      <div
        style={{
          fontFamily: 'var(--font-mono)',
          fontSize: 22,
          fontWeight: 700,
          color: accent ? 'var(--accent-strong)' : 'var(--text)',
        }}
      >
        {value}
      </div>
    </div>
  );
}
