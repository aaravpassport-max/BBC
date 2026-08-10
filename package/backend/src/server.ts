import { createServer } from 'http';
import { createApp } from './app';
import { startBackgroundJobs } from './jobs/scheduler';
import { assertTestOtpConfigSafe } from './modules/auth/otp-test';
import { attachRealtimeServer } from './modules/realtime/realtime.hub';

assertTestOtpConfigSafe();

const app = createApp();
const server = createServer(app);
const port = process.env.PORT || 3000;

attachRealtimeServer(server);

server.listen(port, () => {
  console.log(`Logistics Super App backend listening on port ${port}`);
  startBackgroundJobs();
  console.log('Background jobs started (dispatch offer expiry sweep, every 5s).');
  console.log('WebSocket realtime available at /v1/realtime/ws');
});
