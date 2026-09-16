jest.mock('jsonwebtoken', () => ({
  sign: jest.fn(() => 'mock-jwt-assertion'),
}));

const FCM_ORIGINAL_ENV = { ...process.env };

const MOCK_SA = {
  client_email: 'fcm@test.iam.gserviceaccount.com',
  private_key: 'mock-private-key',
};

afterEach(() => {
  process.env = { ...FCM_ORIGINAL_ENV };
  jest.restoreAllMocks();
});

describe('FCM provider: isConfigured', () => {
  it('is false when credentials are missing', async () => {
    delete process.env.FCM_PROJECT_ID;
    delete process.env.FCM_SERVICE_ACCOUNT_JSON;
    jest.resetModules();
    const provider = await import('../fcm.provider');
    expect(provider.isConfigured()).toBe(false);
  });

  it('is true when both project id and service account json are set', async () => {
    process.env.FCM_PROJECT_ID = 'portmystuff-test';
    process.env.FCM_SERVICE_ACCOUNT_JSON = JSON.stringify(MOCK_SA);
    jest.resetModules();
    const provider = await import('../fcm.provider');
    expect(provider.isConfigured()).toBe(true);
  });
});

describe('FCM provider: sendPush (mocked HTTP)', () => {
  beforeEach(() => {
    process.env.FCM_PROJECT_ID = 'portmystuff-test';
    process.env.FCM_SERVICE_ACCOUNT_JSON = JSON.stringify(MOCK_SA);
  });

  it('skips web pseudo-tokens and sends to real FCM tokens', async () => {
    jest.resetModules();
    const provider = await import('../fcm.provider');
    const fetchSpy = jest
      .spyOn(global, 'fetch')
      .mockResolvedValueOnce({
        ok: true,
        json: async () => ({ access_token: 'mock-oauth-token', expires_in: 3600 }),
      } as Response)
      .mockResolvedValueOnce({ ok: true, json: async () => ({ name: 'projects/test/messages/1' }) } as Response);

    const result = await provider.sendPush({
      tokens: ['web_device123', 'real-fcm-token-abc'],
      templateId: 'driver_assigned',
    });

    expect(fetchSpy).toHaveBeenCalledTimes(2);
    const [, fcmCall] = fetchSpy.mock.calls;
    expect(fcmCall[0]).toContain('/v1/projects/portmystuff-test/messages:send');
    const body = JSON.parse(fcmCall[1]?.body as string);
    expect(body.message.token).toBe('real-fcm-token-abc');
    expect(body.message.notification.title).toBe('Driver assigned');
    expect(result.successCount).toBe(1);
    expect(result.failureCount).toBe(0);
  });

  it('collects invalid tokens from FCM error responses', async () => {
    jest.resetModules();
    const provider = await import('../fcm.provider');
    jest
      .spyOn(global, 'fetch')
      .mockResolvedValueOnce({
        ok: true,
        json: async () => ({ access_token: 'mock-oauth-token', expires_in: 3600 }),
      } as Response)
      .mockResolvedValueOnce({
        ok: false,
        json: async () => ({ error: { details: [{ errorCode: 'UNREGISTERED' }] } }),
      } as Response);

    const result = await provider.sendPush({
      tokens: ['stale-token'],
      templateId: 'new_offer',
    });

    expect(result.invalidTokens).toEqual(['stale-token']);
    expect(result.failureCount).toBe(1);
  });
});
