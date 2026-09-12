/**
 * Masked calling via Exotel (https://developer.exotel.com/api/).
 * Activates when EXOTEL_SID, EXOTEL_API_KEY, EXOTEL_API_TOKEN, and
 * EXOTEL_CALLER_ID are set — falls back to a dev-only tel: URI otherwise.
 */

const EXOTEL_API_BASE = 'https://api.exotel.com/v1/Accounts';

export function isConfigured(): boolean {
  return Boolean(
    process.env.EXOTEL_SID &&
      process.env.EXOTEL_API_KEY &&
      process.env.EXOTEL_API_TOKEN &&
      process.env.EXOTEL_CALLER_ID
  );
}

export interface MaskedCallResult {
  callUri: string;
  displayNumber: string;
  providerRef?: string;
}

export async function initiateMaskedCall(params: {
  fromPhone: string;
  toPhone: string;
}): Promise<MaskedCallResult> {
  const { fromPhone, toPhone } = params;

  if (!isConfigured()) {
    const masked = `+91 ******${toPhone.slice(-4)}`;
    return { callUri: `tel:${toPhone}`, displayNumber: masked };
  }

  const sid = process.env.EXOTEL_SID as string;
  const callerId = process.env.EXOTEL_CALLER_ID as string;
  const auth = Buffer.from(
    `${process.env.EXOTEL_API_KEY}:${process.env.EXOTEL_API_TOKEN}`
  ).toString('base64');

  const body = new URLSearchParams({
    From: fromPhone.replace(/\D/g, ''),
    To: toPhone.replace(/\D/g, ''),
    CallerId: callerId,
    CallType: 'trans',
    Record: 'false',
  });

  const res = await fetch(`${EXOTEL_API_BASE}/${sid}/Calls/connect.json`, {
    method: 'POST',
    headers: {
      Authorization: `Basic ${auth}`,
      'Content-Type': 'application/x-www-form-urlencoded',
    },
    body,
  });

  if (!res.ok) {
    const text = await res.text().catch(() => '');
    throw new Error(`Exotel call failed (${res.status}): ${text}`);
  }

  const data = (await res.json()) as {
    Call?: { Sid?: string; PhoneNumberSid?: string };
  };
  const providerRef = data.Call?.Sid;
  const masked = `+91 ******${callerId.slice(-4)}`;

  return {
    callUri: `tel:${callerId}`,
    displayNumber: masked,
    providerRef,
  };
}
