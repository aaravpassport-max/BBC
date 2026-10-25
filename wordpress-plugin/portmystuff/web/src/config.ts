export type AppConfig = {
  appBase: string;
  appPath: string;
  apiBase: string;
  adminBase: string;
  opsBase: string;
  nonce: string;
  siteUrl: string;
  wpLoginUrl: string;
  isWpUser: boolean;
  canAdmin: boolean;
  canOps: boolean;
};

declare global {
  interface Window {
    PORTMYSTUFF_CONFIG?: AppConfig;
  }
}

export function getConfig(): AppConfig {
  const c = window.PORTMYSTUFF_CONFIG;
  if (!c) {
    throw new Error('PORTMYSTUFF_CONFIG is missing');
  }
  return c;
}
