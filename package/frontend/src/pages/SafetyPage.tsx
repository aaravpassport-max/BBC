import { useNavigate } from 'react-router-dom';
import { Screen } from '../components/Screen';
import { Button } from '../components/Button';

const SAFETY_TIPS = [
  { icon: '📍', title: 'Share trip details', body: 'Use live tracking to share your trip status with family or colleagues.' },
  { icon: '🔐', title: 'Verify pickup OTP', body: 'Only hand over goods after your driver enters the correct pickup code.' },
  { icon: '📞', title: 'Masked calling', body: 'Call your driver through the app — your real number stays private.' },
  { icon: '🆘', title: 'Emergency SOS', body: 'During an active trip, tap SOS on the tracking screen to alert our safety team instantly.' },
  { icon: '⭐', title: 'Rate every trip', body: 'Your ratings help us remove unsafe partners and reward great service.' },
];

export function SafetyPage() {
  const navigate = useNavigate();

  return (
    <Screen eyebrow="Safety" title="Safety center" onBack={() => navigate('/profile')}>
      <p style={{ fontSize: 14, color: 'var(--text-muted)', marginTop: 0 }}>
        Your safety matters. Here is how PORTMYSTUFF keeps every delivery secure.
      </p>

      <div style={{ display: 'flex', flexDirection: 'column', gap: 12 }}>
        {SAFETY_TIPS.map((tip) => (
          <div
            key={tip.title}
            style={{
              border: '1px solid var(--border)',
              borderRadius: 12,
              padding: 16,
              background: 'var(--surface)',
            }}
          >
            <div style={{ fontSize: 20, marginBottom: 6 }}>{tip.icon}</div>
            <div style={{ fontWeight: 600, fontSize: 15 }}>{tip.title}</div>
            <div style={{ fontSize: 13, color: 'var(--text-muted)', marginTop: 4 }}>{tip.body}</div>
          </div>
        ))}
      </div>

      <div
        style={{
          marginTop: 20,
          padding: 16,
          borderRadius: 12,
          background: 'var(--danger-soft, #fff1f0)',
          border: '1px solid var(--danger, #ff4d4f)',
        }}
      >
        <div style={{ fontWeight: 700, color: 'var(--danger)' }}>In an emergency?</div>
        <p style={{ fontSize: 13, color: 'var(--text-muted)', margin: '8px 0 12px' }}>
          If you are on an active trip, open tracking and tap SOS. For immediate danger, call local emergency services (112).
        </p>
        <Button variant="danger" onClick={() => navigate('/history')}>
          Go to my trips
        </Button>
      </div>
    </Screen>
  );
}
