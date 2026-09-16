/** Dev-only HTTP routes (simulated payments, manual dispatch) — never registered in production. */
export function isDevRoutesEnabled(): boolean {
  return process.env.NODE_ENV !== 'production';
}

export function isProduction(): boolean {
  return process.env.NODE_ENV === 'production';
}
