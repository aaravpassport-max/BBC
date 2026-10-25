import { ErrorBoundary } from './ErrorBoundary';
import { LiveMap } from './LiveMap';

type LiveMapProps = Parameters<typeof LiveMap>[0];

export function SafeLiveMap(props: LiveMapProps) {
  return (
    <ErrorBoundary
      label="Map"
      fallback={
        <div
          style={{
            height: 220,
            borderRadius: 16,
            border: '1px solid var(--border)',
            background: 'var(--surface-raised)',
            display: 'flex',
            alignItems: 'center',
            justifyContent: 'center',
            color: 'var(--text-muted)',
            fontSize: 13,
            textAlign: 'center',
            padding: 16,
          }}
        >
          Map preview unavailable — you can still go online and accept jobs.
        </div>
      }
    >
      <LiveMap {...props} />
    </ErrorBoundary>
  );
}
