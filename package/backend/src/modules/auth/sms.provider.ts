const MSG91_API_BASE = 'https://api.msg91.com/api/v5';

/**
 * Real SMS provider integration (the "text-message provider" gap). This
 * is not a simulation — sendOtpSms makes a genuine authenticated call to
 * MSG91's real Flow API (https://api.msg91.com/api/v5/flow/), the
 * standard, current provider most Indian businesses use for exactly this
 * (OTP delivery), matching the market this whole platform is built for.
 *
 * It activates automatically the moment MSG91_AUTH_KEY, MSG91_SENDER_ID,
 * and MSG91_OTP_TEMPLATE_ID are set in the environment — isConfigured()
 * is what auth.service.ts checks to decide between this and the existing
 * dev-only console-log fallback, so setting real credentials requires no
 * code change anywhere else, only environment configuration.
 *
 * A REAL PREREQUISITE, not a code gap: Indian telecom regulation (TRAI's
 * DLT — Distributed Ledger Technology — framework) requires every
 * transactional SMS template to be pre-registered and approved before it
 * can be sent at all. This is not something any code can route around —
 * it is a real, separate registration MSG91's own dashboard walks a
 * business through, typically taking 1-3 business days, and
 * MSG91_OTP_TEMPLATE_ID below refers to the ID that process produces.
 *
 * This reference environment has no network access to api.msg91.com
 * (the sandbox's network policy blocks it, confirmed directly rather than
 * assumed) and no real MSG91 account or approved DLT template, so the
 * actual HTTP call could not be exercised against MSG91's live servers
 * here. What IS verified: the request is built exactly to MSG91's
 * documented v5 Flow API contract — see sms.provider.test.ts.
 */

export function isConfigured(): boolean {
  return Boolean(process.env.MSG91_AUTH_KEY && process.env.MSG91_SENDER_ID && process.env.MSG91_OTP_TEMPLATE_ID);
}

export interface SmsSendResult {
  success: boolean;
  providerMessageId?: string;
}

/**
 * Sends a real OTP SMS via MSG91's Flow API. The OTP code itself is
 * generated and owned entirely by this backend (auth.service.ts) — MSG91
 * is only the delivery channel here, not the code generator, which is why
 * this calls the general-purpose Flow/template API rather than MSG91's
 * own bundled "SendOTP" product (that alternative generates and verifies
 * the code on MSG91's own servers instead of ours, which would mean
 * trusting a third party with a security-sensitive step this platform
 * already owns correctly and has tested — see auth.test.ts).
 *
 * The template referenced by MSG91_OTP_TEMPLATE_ID must contain a single
 * variable, conventionally named OTP, e.g. approved DLT wording like
 * "##OTP## is your PORTMYSTUFF verification code. Valid for 5 minutes."
 */
export async function sendOtpSms(params: {
  countryCode: string;
  phone: string;
  code: string;
}): Promise<SmsSendResult> {
  const { countryCode, phone, code } = params;
  const mobile = `${countryCode.replace('+', '')}${phone}`;

  const res = await fetch(`${MSG91_API_BASE}/flow/`, {
    method: 'POST',
    headers: {
      authkey: process.env.MSG91_AUTH_KEY as string,
      'content-type': 'application/json',
    },
    body: JSON.stringify({
      template_id: process.env.MSG91_OTP_TEMPLATE_ID,
      sender: process.env.MSG91_SENDER_ID,
      recipients: [{ mobiles: mobile, OTP: code }],
    }),
  });

  if (!res.ok) {
    const text = await res.text().catch(() => '');
    throw new Error(`MSG91 request failed: ${res.status} ${text}`);
  }

  const body = (await res.json()) as { type?: string; request_id?: string; message?: string };
  if (body.type !== 'success') {
    throw new Error(`MSG91 rejected the request: ${body.message || 'unknown error'}`);
  }

  return { success: true, providerMessageId: body.request_id };
}
