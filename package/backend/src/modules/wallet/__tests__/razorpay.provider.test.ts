import { createHmac } from 'crypto';

const ORIGINAL_ENV = { ...process.env };

afterEach(() => {
  process.env = { ...ORIGINAL_ENV };
  jest.restoreAllMocks();
});

describe('Razorpay provider: isConfigured', () => {
  it('is false when no credentials are set', async () => {
    delete process.env.RAZORPAY_KEY_ID;
    delete process.env.RAZORPAY_KEY_SECRET;
    jest.resetModules();
    const provider = await import('../razorpay.provider');
    expect(provider.isConfigured()).toBe(false);
  });

  it('is true only when BOTH key id and secret are set', async () => {
    process.env.RAZORPAY_KEY_ID = 'rzp_test_123';
    delete process.env.RAZORPAY_KEY_SECRET;
    jest.resetModules();
    const providerPartial = await import('../razorpay.provider');
    expect(providerPartial.isConfigured()).toBe(false);

    process.env.RAZORPAY_KEY_SECRET = 'a_real_secret';
    jest.resetModules();
    const providerFull = await import('../razorpay.provider');
    expect(providerFull.isConfigured()).toBe(true);
  });
});

describe('Razorpay provider: payment signature verification (real HMAC, per Razorpay\u2019s documented scheme)', () => {
  it('accepts a genuinely correctly-computed signature', async () => {
    process.env.RAZORPAY_KEY_ID = 'rzp_test_123';
    process.env.RAZORPAY_KEY_SECRET = 'a_real_secret';
    jest.resetModules();
    const provider = await import('../razorpay.provider');

    const orderId = 'order_ABC123';
    const paymentId = 'pay_XYZ789';
    const realSignature = createHmac('sha256', 'a_real_secret').update(`${orderId}|${paymentId}`).digest('hex');

    const valid = provider.verifyPaymentSignature({ orderId, paymentId, signature: realSignature });
    expect(valid).toBe(true);
  });

  it('rejects a tampered signature', async () => {
    process.env.RAZORPAY_KEY_ID = 'rzp_test_123';
    process.env.RAZORPAY_KEY_SECRET = 'a_real_secret';
    jest.resetModules();
    const provider = await import('../razorpay.provider');

    const valid = provider.verifyPaymentSignature({
      orderId: 'order_ABC123',
      paymentId: 'pay_XYZ789',
      signature: 'not_even_close_to_a_real_signature',
    });
    expect(valid).toBe(false);
  });

  it('rejects a signature computed with the WRONG secret (proving the secret genuinely participates in the check)', async () => {
    process.env.RAZORPAY_KEY_ID = 'rzp_test_123';
    process.env.RAZORPAY_KEY_SECRET = 'the_real_secret';
    jest.resetModules();
    const provider = await import('../razorpay.provider');

    const orderId = 'order_ABC123';
    const paymentId = 'pay_XYZ789';
    const signatureFromWrongSecret = createHmac('sha256', 'a_different_secret_entirely')
      .update(`${orderId}|${paymentId}`)
      .digest('hex');

    const valid = provider.verifyPaymentSignature({ orderId, paymentId, signature: signatureFromWrongSecret });
    expect(valid).toBe(false);
  });

  it('returns false (never throws) when not configured, rather than crashing on a missing secret', async () => {
    delete process.env.RAZORPAY_KEY_ID;
    delete process.env.RAZORPAY_KEY_SECRET;
    jest.resetModules();
    const provider = await import('../razorpay.provider');

    const valid = provider.verifyPaymentSignature({ orderId: 'x', paymentId: 'y', signature: 'z' });
    expect(valid).toBe(false);
  });
});

describe('Razorpay provider: webhook signature verification (separate secret from the payment signature above)', () => {
  it('accepts a genuinely correctly-computed webhook signature over the raw body', async () => {
    process.env.RAZORPAY_WEBHOOK_SECRET = 'a_webhook_secret';
    jest.resetModules();
    const provider = await import('../razorpay.provider');

    const rawBody = JSON.stringify({ event: 'payment.captured', payload: { payment: { entity: { order_id: 'order_1' } } } });
    const realSignature = createHmac('sha256', 'a_webhook_secret').update(rawBody).digest('hex');

    expect(provider.verifyWebhookSignature(rawBody, realSignature)).toBe(true);
  });

  it('rejects a signature computed over a DIFFERENT body than what was sent', async () => {
    process.env.RAZORPAY_WEBHOOK_SECRET = 'a_webhook_secret';
    jest.resetModules();
    const provider = await import('../razorpay.provider');

    const originalBody = JSON.stringify({ event: 'payment.captured', amount: 100 });
    const tamperedBody = JSON.stringify({ event: 'payment.captured', amount: 100000 });
    const signatureForOriginal = createHmac('sha256', 'a_webhook_secret').update(originalBody).digest('hex');

    expect(provider.verifyWebhookSignature(tamperedBody, signatureForOriginal)).toBe(false);
  });

  it('returns false when RAZORPAY_WEBHOOK_SECRET is not configured', async () => {
    delete process.env.RAZORPAY_WEBHOOK_SECRET;
    jest.resetModules();
    const provider = await import('../razorpay.provider');
    expect(provider.verifyWebhookSignature('{}', 'anything')).toBe(false);
  });
});

describe('Razorpay provider: order creation (request shape, verified against a mocked HTTP layer — this sandbox has no network access to api.razorpay.com)', () => {
  it('builds the request exactly to Razorpay\u2019s documented Orders API contract', async () => {
    process.env.RAZORPAY_KEY_ID = 'rzp_test_123';
    process.env.RAZORPAY_KEY_SECRET = 'a_real_secret';
    jest.resetModules();
    const provider = await import('../razorpay.provider');

    const fetchSpy = jest.spyOn(global, 'fetch').mockResolvedValue({
      ok: true,
      json: async () => ({ id: 'order_mocked123', amount: 50000, currency: 'INR', status: 'created' }),
    } as Response);

    const order = await provider.createOrder({ amountRupees: 500, receipt: 'topup_test_123' });

    expect(fetchSpy).toHaveBeenCalledTimes(1);
    const [url, options] = fetchSpy.mock.calls[0];
    expect(url).toBe('https://api.razorpay.com/v1/orders');
    expect(options?.method).toBe('POST');

    const authHeader = (options?.headers as Record<string, string>).Authorization;
    const decoded = Buffer.from(authHeader.replace('Basic ', ''), 'base64').toString();
    expect(decoded).toBe('rzp_test_123:a_real_secret');

    const body = JSON.parse(options?.body as string);
    expect(body.amount).toBe(50000);
    expect(body.currency).toBe('INR');
    expect(body.receipt).toBe('topup_test_123');

    expect(order.id).toBe('order_mocked123');
  });

  it('throws a clear error when Razorpay is not configured, rather than attempting a doomed network call', async () => {
    delete process.env.RAZORPAY_KEY_ID;
    delete process.env.RAZORPAY_KEY_SECRET;
    jest.resetModules();
    const provider = await import('../razorpay.provider');

    await expect(provider.createOrder({ amountRupees: 100, receipt: 'x' })).rejects.toThrow(/not configured/);
  });

  it('surfaces a Razorpay-side failure clearly rather than swallowing it', async () => {
    process.env.RAZORPAY_KEY_ID = 'rzp_test_123';
    process.env.RAZORPAY_KEY_SECRET = 'a_real_secret';
    jest.resetModules();
    const provider = await import('../razorpay.provider');

    jest.spyOn(global, 'fetch').mockResolvedValue({
      ok: false,
      status: 401,
      text: async () => '{"error":{"description":"Authentication failed"}}',
    } as Response);

    await expect(provider.createOrder({ amountRupees: 100, receipt: 'x' })).rejects.toThrow(/401/);
  });
});
