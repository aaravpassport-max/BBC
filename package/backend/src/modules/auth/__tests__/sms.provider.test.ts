const ORIGINAL_ENV = { ...process.env };

afterEach(() => {
  process.env = { ...ORIGINAL_ENV };
  jest.restoreAllMocks();
});

describe('SMS provider (MSG91): isConfigured', () => {
  it('is false when no credentials are set', async () => {
    delete process.env.MSG91_AUTH_KEY;
    delete process.env.MSG91_SENDER_ID;
    delete process.env.MSG91_OTP_TEMPLATE_ID;
    jest.resetModules();
    const provider = await import('../sms.provider');
    expect(provider.isConfigured()).toBe(false);
  });

  it('is true only when ALL THREE of auth key, sender id, and template id are set', async () => {
    process.env.MSG91_AUTH_KEY = 'a_real_authkey';
    delete process.env.MSG91_SENDER_ID;
    delete process.env.MSG91_OTP_TEMPLATE_ID;
    jest.resetModules();
    let provider = await import('../sms.provider');
    expect(provider.isConfigured()).toBe(false);

    process.env.MSG91_SENDER_ID = 'WAYBIL';
    jest.resetModules();
    provider = await import('../sms.provider');
    expect(provider.isConfigured()).toBe(false);

    process.env.MSG91_OTP_TEMPLATE_ID = 'a_real_dlt_approved_template_id';
    jest.resetModules();
    provider = await import('../sms.provider');
    expect(provider.isConfigured()).toBe(true);
  });
});

describe('SMS provider (MSG91): OTP send (request shape, verified against a mocked HTTP layer — this sandbox has no network access to api.msg91.com)', () => {
  async function configuredProvider() {
    process.env.MSG91_AUTH_KEY = 'a_real_authkey';
    process.env.MSG91_SENDER_ID = 'WAYBIL';
    process.env.MSG91_OTP_TEMPLATE_ID = 'a_real_dlt_approved_template_id';
    jest.resetModules();
    return import('../sms.provider');
  }

  it('builds the request exactly to MSG91\u2019s documented v5 Flow API contract', async () => {
    const provider = await configuredProvider();
    const fetchSpy = jest.spyOn(global, 'fetch').mockResolvedValue({
      ok: true,
      json: async () => ({ type: 'success', request_id: 'mocked-request-id-123' }),
    } as Response);

    const result = await provider.sendOtpSms({ countryCode: '+91', phone: '9876543210', code: '482913' });

    expect(fetchSpy).toHaveBeenCalledTimes(1);
    const [url, options] = fetchSpy.mock.calls[0];
    expect(url).toBe('https://api.msg91.com/api/v5/flow/');
    expect(options?.method).toBe('POST');

    const headers = options?.headers as Record<string, string>;
    expect(headers.authkey).toBe('a_real_authkey');
    expect(headers['content-type']).toBe('application/json');

    const body = JSON.parse(options?.body as string);
    expect(body.template_id).toBe('a_real_dlt_approved_template_id');
    expect(body.sender).toBe('WAYBIL');
    expect(body.recipients).toEqual([{ mobiles: '919876543210', OTP: '482913' }]);

    expect(result.success).toBe(true);
    expect(result.providerMessageId).toBe('mocked-request-id-123');
  });

  it('strips the + from the country code and concatenates it directly with the phone number, matching MSG91\u2019s expected mobile format', async () => {
    const provider = await configuredProvider();
    const fetchSpy = jest
      .spyOn(global, 'fetch')
      .mockResolvedValue({ ok: true, json: async () => ({ type: 'success', request_id: 'x' }) } as Response);

    await provider.sendOtpSms({ countryCode: '+91', phone: '9000011111', code: '123456' });

    const body = JSON.parse((fetchSpy.mock.calls[0][1]?.body as string) || '{}');
    expect(body.recipients[0].mobiles).toBe('919000011111');
    expect(body.recipients[0].mobiles).not.toContain('+');
  });

  it('throws a real error when MSG91 returns a non-2xx HTTP response', async () => {
    const provider = await configuredProvider();
    jest.spyOn(global, 'fetch').mockResolvedValue({
      ok: false,
      status: 401,
      text: async () => 'Invalid authkey',
    } as Response);

    await expect(
      provider.sendOtpSms({ countryCode: '+91', phone: '9876543210', code: '111111' })
    ).rejects.toThrow(/401/);
  });

  it('throws a real error when MSG91 responds 200 but rejects the request internally (their own error-in-body pattern)', async () => {
    const provider = await configuredProvider();
    jest.spyOn(global, 'fetch').mockResolvedValue({
      ok: true,
      json: async () => ({ type: 'error', message: 'Template not approved' }),
    } as Response);

    await expect(
      provider.sendOtpSms({ countryCode: '+91', phone: '9876543210', code: '111111' })
    ).rejects.toThrow(/Template not approved/);
  });
});
