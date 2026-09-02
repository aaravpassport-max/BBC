/**
 * RazorpayX payout integration for driver settlement batches.
 * Activates when RAZORPAY_KEY_ID + RAZORPAY_KEY_SECRET are set (same keys as payments).
 */

const RAZORPAYX_API = 'https://api.razorpay.com/v1';

export function isConfigured(): boolean {
  return Boolean(process.env.RAZORPAY_KEY_ID && process.env.RAZORPAY_KEY_SECRET);
}

function authHeader(): string {
  const keyId = process.env.RAZORPAY_KEY_ID as string;
  const keySecret = process.env.RAZORPAY_KEY_SECRET as string;
  return 'Basic ' + Buffer.from(`${keyId}:${keySecret}`).toString('base64');
}

export interface PayoutResult {
  providerRef: string;
  status: string;
}

/** Submits a single driver payout via RazorpayX Contacts + Fund Account + Payout API. */
export async function submitDriverPayout(params: {
  driverId: string;
  amountRupees: number;
  accountNumber: string;
  ifsc: string;
  name: string;
  reference: string;
}): Promise<PayoutResult> {
  if (!isConfigured()) {
    return { providerRef: `sim_payout_${params.reference}`, status: 'processed' };
  }

  const amountPaise = Math.round(params.amountRupees * 100);

  const contactRes = await fetch(`${RAZORPAYX_API}/contacts`, {
    method: 'POST',
    headers: { Authorization: authHeader(), 'Content-Type': 'application/json' },
    body: JSON.stringify({
      name: params.name,
      reference_id: `driver_${params.driverId}`,
      type: 'vendor',
    }),
  });
  if (!contactRes.ok) {
    const text = await contactRes.text();
    throw new Error(`RazorpayX contact failed: ${text}`);
  }
  const contact = (await contactRes.json()) as { id: string };

  const fundRes = await fetch(`${RAZORPAYX_API}/fund_accounts`, {
    method: 'POST',
    headers: { Authorization: authHeader(), 'Content-Type': 'application/json' },
    body: JSON.stringify({
      contact_id: contact.id,
      account_type: 'bank_account',
      bank_account: {
        name: params.name,
        ifsc: params.ifsc,
        account_number: params.accountNumber,
      },
    }),
  });
  if (!fundRes.ok) {
    const text = await fundRes.text();
    throw new Error(`RazorpayX fund account failed: ${text}`);
  }
  const fund = (await fundRes.json()) as { id: string };

  const payoutRes = await fetch(`${RAZORPAYX_API}/payouts`, {
    method: 'POST',
    headers: {
      Authorization: authHeader(),
      'Content-Type': 'application/json',
      'X-Payout-Idempotency': params.reference,
    },
    body: JSON.stringify({
      account_number: process.env.RAZORPAYX_ACCOUNT_NUMBER || '2323230053536767',
      fund_account_id: fund.id,
      amount: amountPaise,
      currency: 'INR',
      mode: 'IMPS',
      purpose: 'payout',
      reference_id: params.reference,
    }),
  });
  if (!payoutRes.ok) {
    const text = await payoutRes.text();
    throw new Error(`RazorpayX payout failed: ${text}`);
  }
  const payout = (await payoutRes.json()) as { id: string; status: string };
  return { providerRef: payout.id, status: payout.status };
}
