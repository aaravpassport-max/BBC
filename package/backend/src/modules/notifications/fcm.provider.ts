import jwt from 'jsonwebtoken';

const FCM_SCOPE = 'https://www.googleapis.com/auth/firebase.messaging';
const OAUTH_TOKEN_URL = 'https://oauth2.googleapis.com/token';

interface ServiceAccount {
  client_email: string;
  private_key: string;
}

let cachedAccessToken: { token: string; expiresAt: number } | null = null;

export function isConfigured(): boolean {
  return Boolean(process.env.FCM_PROJECT_ID && process.env.FCM_SERVICE_ACCOUNT_JSON);
}

function parseServiceAccount(): ServiceAccount {
  const raw = process.env.FCM_SERVICE_ACCOUNT_JSON;
  if (!raw) throw new Error('FCM_SERVICE_ACCOUNT_JSON is not set');
  return JSON.parse(raw) as ServiceAccount;
}

async function getAccessToken(): Promise<string> {
  if (cachedAccessToken && cachedAccessToken.expiresAt > Date.now() + 60_000) {
    return cachedAccessToken.token;
  }

  const sa = parseServiceAccount();
  const now = Math.floor(Date.now() / 1000);
  const assertion = jwt.sign(
    {
      iss: sa.client_email,
      sub: sa.client_email,
      aud: OAUTH_TOKEN_URL,
      iat: now,
      exp: now + 3600,
      scope: FCM_SCOPE,
    },
    sa.private_key,
    { algorithm: 'RS256' }
  );

  const res = await fetch(OAUTH_TOKEN_URL, {
    method: 'POST',
    headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
    body: new URLSearchParams({
      grant_type: 'urn:ietf:params:oauth:grant-type:jwt-bearer',
      assertion,
    }),
  });

  if (!res.ok) {
    const text = await res.text().catch(() => '');
    throw new Error(`FCM OAuth token request failed: ${res.status} ${text}`);
  }

  const body = (await res.json()) as { access_token: string; expires_in: number };
  cachedAccessToken = { token: body.access_token, expiresAt: Date.now() + body.expires_in * 1000 };
  return body.access_token;
}

export interface PushSendResult {
  successCount: number;
  failureCount: number;
  invalidTokens: string[];
}

const TEMPLATE_COPY: Record<string, { title: string; body: string }> = {
  driver_assigned: { title: 'Driver assigned', body: 'Your driver is on the way.' },
  driver_arrived: { title: 'Driver arrived', body: 'Your driver has arrived at the pickup point.' },
  trip_started: { title: 'Trip started', body: 'Your delivery is in progress.' },
  trip_completed: { title: 'Trip completed', body: 'Your delivery has been completed.' },
  new_offer: { title: 'New trip offer', body: 'You have a new delivery offer nearby.' },
  offer_expired: { title: 'Offer expired', body: 'A trip offer has expired.' },
  chat_message: { title: 'New message', body: 'You have a new chat message.' },
  booking_cancelled: { title: 'Booking cancelled', body: 'Your booking has been cancelled.' },
};

function templateToCopy(templateId: string): { title: string; body: string } {
  return TEMPLATE_COPY[templateId] || { title: 'PORTMYSTUFF', body: 'You have a new notification.' };
}

export async function sendPush(params: {
  tokens: string[];
  templateId: string;
  data?: Record<string, string>;
}): Promise<PushSendResult> {
  const { tokens, templateId, data } = params;
  if (tokens.length === 0) return { successCount: 0, failureCount: 0, invalidTokens: [] };

  const projectId = process.env.FCM_PROJECT_ID as string;
  const accessToken = await getAccessToken();
  const copy = templateToCopy(templateId);

  let successCount = 0;
  let failureCount = 0;
  const invalidTokens: string[] = [];

  await Promise.all(
    tokens.map(async (token) => {
      if (token.startsWith('web_')) return;

      try {
        const res = await fetch(`https://fcm.googleapis.com/v1/projects/${projectId}/messages:send`, {
          method: 'POST',
          headers: {
            Authorization: `Bearer ${accessToken}`,
            'Content-Type': 'application/json',
          },
          body: JSON.stringify({
            message: {
              token,
              notification: { title: copy.title, body: copy.body },
              data: { template_id: templateId, ...data },
              android: { priority: 'HIGH' },
              apns: { payload: { aps: { sound: 'default' } } },
            },
          }),
        });

        if (res.ok) {
          successCount++;
          return;
        }

        const errBody = (await res.json().catch(() => ({}))) as {
          error?: { details?: Array<{ errorCode?: string }> };
        };
        const errorCode = errBody.error?.details?.[0]?.errorCode;
        if (errorCode === 'UNREGISTERED' || errorCode === 'INVALID_ARGUMENT') {
          invalidTokens.push(token);
        }
        failureCount++;
      } catch {
        failureCount++;
      }
    })
  );

  return { successCount, failureCount, invalidTokens };
}
