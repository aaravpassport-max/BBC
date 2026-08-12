import { useEffect, useState, type ReactNode } from 'react';
import { Routes, Route, useNavigate, useParams, Navigate } from 'react-router-dom';
import { AppShell } from '@/shared/AppShell';
import { Button } from '@/shared/Button';
import { requestOtp, verifyOtp } from '@/api/auth';
import { setAccessToken, clearAccessToken, hasAccessToken } from '@/api/client';
import { createBooking, getBooking, getQuotes, listBookings, triggerSos } from '@/api/bookings';
import type { Booking, Quote } from '@/api/bookings';
import styles from './CustomerApp.module.css';

function LoginScreen() {
  const nav = useNavigate();
  const [phone, setPhone] = useState('9000000001');
  const [otpId, setOtpId] = useState('');
  const [code, setCode] = useState('');
  const [step, setStep] = useState<'phone' | 'otp'>('phone');
  const [error, setError] = useState('');

  async function sendOtp() {
    setError('');
    const res = await requestOtp(phone);
    setOtpId(res.otp_id);
    setStep('otp');
  }

  async function verify() {
    setError('');
    try {
      const res = await verifyOtp(otpId, code);
      setAccessToken(res.access_token);
      nav('/customer/home');
    } catch (e) {
      setError(e instanceof Error ? e.message : 'Login failed');
    }
  }

  return (
    <AppShell title="Customer" subtitle="Book rides and parcels" backTo="/">
      <div className={styles.panel}>
        {step === 'phone' ? (
          <>
            <input value={phone} onChange={(e) => setPhone(e.target.value)} placeholder="Phone" />
            <Button onClick={sendOtp}>Send OTP</Button>
          </>
        ) : (
          <>
            <input value={code} onChange={(e) => setCode(e.target.value)} placeholder="OTP (demo 111111)" />
            <Button onClick={verify}>Verify & continue</Button>
          </>
        )}
        {error ? <p className={styles.error}>{error}</p> : null}
      </div>
    </AppShell>
  );
}

function HomeScreen() {
  const nav = useNavigate();
  return (
    <AppShell title="Home" backTo="/">
      <div className={styles.actions}>
        <Button onClick={() => nav('/customer/book/ride')}>Book a ride</Button>
        <Button onClick={() => nav('/customer/book/parcel')}>Send a parcel</Button>
        <Button variant="secondary" onClick={() => nav('/customer/bookings')}>
          My bookings
        </Button>
        <Button
          variant="secondary"
          onClick={() => {
            clearAccessToken();
            nav('/customer');
          }}
        >
          Log out
        </Button>
      </div>
    </AppShell>
  );
}

function BookScreen() {
  const { type } = useParams<{ type: string }>();
  const bookingType = type === 'parcel' ? 'parcel' : 'ride';
  const nav = useNavigate();
  const [quotes, setQuotes] = useState<Quote[]>([]);
  const [loading, setLoading] = useState(false);

  async function loadQuotes() {
    setLoading(true);
    try {
      const res = await getQuotes(bookingType);
      setQuotes(res.quotes);
    } finally {
      setLoading(false);
    }
  }

  async function book(quoteId: string) {
    const booking = await createBooking(quoteId);
    nav(`/customer/track/${booking.id}`);
  }

  return (
    <AppShell title={bookingType === 'ride' ? 'Book ride' : 'Send parcel'} backTo="/customer/home">
      <Button onClick={loadQuotes} disabled={loading}>
        {loading ? 'Loading…' : 'Get quotes'}
      </Button>
      <div className={styles.quoteList}>
        {quotes.map((q) => (
          <button key={q.quote_id} className={styles.quote} onClick={() => book(q.quote_id)}>
            <strong>{q.vehicle_category}</strong>
            <span>₹{q.fare_breakdown.final_fare}</span>
          </button>
        ))}
      </div>
    </AppShell>
  );
}

function TrackScreen() {
  const { id = '' } = useParams<{ id: string }>();
  const [booking, setBooking] = useState<Booking | null>(null);

  useEffect(() => {
    if (id) getBooking(id).then(setBooking);
  }, [id]);

  if (!booking) {
    return <AppShell title="Tracking" backTo="/customer/home">Loading…</AppShell>;
  }

  return (
    <AppShell title="Live trip" backTo="/customer/home">
      <div className={styles.panel}>
        <p>Status: <strong>{booking.status}</strong></p>
        <p>ID: {booking.id.slice(0, 8)}…</p>
        <Button onClick={() => getBooking(id).then(setBooking)}>Refresh</Button>
        <Button variant="danger" onClick={() => triggerSos(booking.id)}>SOS</Button>
      </div>
    </AppShell>
  );
}

function BookingsScreen() {
  const [items, setItems] = useState<Booking[]>([]);
  useEffect(() => {
    listBookings().then((r) => setItems(r.items));
  }, []);
  return (
    <AppShell title="My bookings" backTo="/customer/home">
      {items.map((b) => (
        <div key={b.id} className={styles.listItem}>
          {b.booking_type} — {b.status}
        </div>
      ))}
    </AppShell>
  );
}

function CustomerGate({ children }: { children: ReactNode }) {
  if (!hasAccessToken()) return <Navigate to="/customer" replace />;
  return <>{children}</>;
}

export function CustomerApp() {
  return (
    <Routes>
      <Route index element={hasAccessToken() ? <Navigate to="/customer/home" replace /> : <LoginScreen />} />
      <Route path="home" element={<CustomerGate><HomeScreen /></CustomerGate>} />
      <Route path="book/:type" element={<CustomerGate><BookScreen /></CustomerGate>} />
      <Route path="track/:id" element={<CustomerGate><TrackScreen /></CustomerGate>} />
      <Route path="bookings" element={<CustomerGate><BookingsScreen /></CustomerGate>} />
    </Routes>
  );
}
