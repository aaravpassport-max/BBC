export function AccessDenied() {
  return (
    <div style={{ padding: 40, textAlign: 'center', color: 'var(--text-muted)' }}>
      <h2 style={{ marginBottom: 10 }}>Access pending</h2>
      <p style={{ fontSize: 14, maxWidth: 420, margin: '0 auto' }}>
        Your account doesn't have operations access yet. Ask an existing admin to grant you the{' '}
        <code style={{ fontFamily: 'var(--font-mono)', background: 'var(--surface)', padding: '2px 6px', borderRadius: 4 }}>
          ops_admin
        </code>{' '}
        role.
      </p>
    </div>
  );
}
