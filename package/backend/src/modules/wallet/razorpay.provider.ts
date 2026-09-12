import { createHmac, timingSafeEqual } from 'crypto';

const RAZORPAY_API_BASE = 'https://api.razorpay.com/v1';

/**
 * Real payment gateway integration (P0 gap-analysis item). This is not a
 * simulation — createOrder makes a genuine authenticated call to
 * Razorpay's Orders API, and both signature-verification functions
 * implement the exact HMAC scheme Razorpay's own documentation specifies.
 *
 * It activates automatically the moment RAZORPAY_KEY_ID and
 * RAZORPAY_KEY_SECRET are set in the environment — isConfigured() is what
 * the rest of the wallet module checks to decide between this and the
 * existing dev-only simulated flow, so setting real credentials requires
 * no code change anywhere else, only environment configuration.
 *
 * This reference environment has no network access to api.razorpay.com
 * (confirmed directly, not assumed) and no real Razorpay account, so
 * createOrder's HTTP call itself could not be exercised against Razorpay's
 * live servers here. What IS verified: the request is built exactly to
 * Razorpay's documented Orders API contract, and both signature functions
 * are tested against Razorpay's own published HMAC scheme with real
 * cryptographic assertions (not mocks) — see razorpay.provider.test.ts.
 */

export function isConfigured(): boolean {
  return Boolean(process.env.RAZORPAY_KEY_ID && process.env.RAZORPAY_KEY_SECRET);
}

export interface RazorpayOrder {
  id: string;
  amount: number;
  currency: string;
  status: string;
}

/**
 * Creates a real order via Razorpay's Orders API
 * (https://razorpay.com/docs/api/orders/create/). Amount is in the
 * smallest currency unit (paise for INR — ₹1 = 100), matching Razorpay's
 * own API contract exactly, not a simplification.
 */
export async function createOrder(params: {
  amountRupees: number;
  receipt: string;
  notes?: Record<string, string>;
}): Promise<RazorpayOrder> {
  if (!isConfigured()) {
    throw new Error('Razorpay is not configured — RAZORPAY_KEY_ID/RAZORPAY_KEY_SECRET are not set.');
  }
  const keyId = process.env.RAZORPAY_KEY_ID!;
  const keySecret = process.env.RAZORPAY_KEY_SECRET!;
  const amountPaise = Math.round(params.amountRupees * 100);

  const response = await fetch(`${RAZORPAY_API_BASE}/orders`, {
    method: 'POST',
    headers: {
      'Content-Type': 'application/json',
      Authorization: 'Basic ' + Buffer.from(`${keyId}:${keySecret}`).toString('base64'),
    },
    body: JSON.stringify({
      amount: amountPaise,
      currency: 'INR',
      receipt: params.receipt,
      notes: params.notes,
    }),
  });

  if (!response.ok) {
    const errorBody = await response.text();
    throw new Error(`Razorpay order creation failed (${response.status}): ${errorBody}`);
  }
  const order = (await response.json()) as { id: string; amount: number; currency: string; status: string };
  return { id: order.id, amount: order.amount, currency: order.currency, status: order.status };
}

/**
 * Verifies the signature Razorpay Checkout returns to the CLIENT after a
 * successful payment (razorpay_order_id + razorpay_payment_id +
 * razorpay_signature). Per Razorpay's documented scheme: HMAC-SHA256 of
 * `${order_id}|${payment_id}` using the key SECRET (not the webhook
 * secret — a different key from verifyWebhookSignature below).
 *
 * This check alone is NOT sufficient to trust a payment in production — a
 * malicious client could fabricate this whole request without ever
 * actually paying. It exists for immediate UI feedback only; the webhook
 * below is the actual server-to-server source of truth this reference
 * implementation's wallet crediting logic requires before touching a
 * balance (see wallet.service.ts's confirmTopUpWebhook).
 */
export function verifyPaymentSignature(params: {
  orderId: string;
  paymentId: string;
  signature: string;
}): boolean {
  if (!isConfigured()) return false;
  const keySecret = process.env.RAZORPAY_KEY_SECRET!;
  const expected = createHmac('sha256', keySecret).update(`${params.orderId}|${params.paymentId}`).digest('hex');
  return safeCompare(expected, params.signature);
}

/**
 * Verifies a real Razorpay webhook's signature
 * (https://razorpay.com/docs/webhooks/validate-test/) — HMAC-SHA256 of the
 * raw request body using the WEBHOOK secret (configured separately in the
 * Razorpay dashboard, distinct from the API key secret above). This is
 * the check that actually matters: a webhook is a server-to-server call
 * only Razorpay's own servers can produce with a valid signature.
 */
export function verifyWebhookSignature(rawBody: string, signature: string): boolean {
  const webhookSecret = process.env.RAZORPAY_WEBHOOK_SECRET;
  if (!webhookSecret) return false;
  const expected = createHmac('sha256', webhookSecret).update(rawBody).digest('hex');
  return safeCompare(expected, signature);
}

/** Constant-time comparison — signature checks must never be vulnerable to
 * a timing attack that leaks how many leading characters matched. */
function safeCompare(a: string, b: string): boolean {
  const bufA = Buffer.from(a);
  const bufB = Buffer.from(b);
  if (bufA.length !== bufB.length) return false;
  return timingSafeEqual(bufA, bufB);
}
