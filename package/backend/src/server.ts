import { createApp } from './app';
import { startBackgroundJobs } from './jobs/scheduler';
import { assertTestOtpConfigSafe } from './modules/auth/otp-test';

assertTestOtpConfigSafe();

const app = createApp();
const port = process.env.PORT || 3000;

app.listen(port, () => {
  console.log(`Logistics Super App backend listening on port ${port}`);
  startBackgroundJobs();
  console.log('Background jobs started (dispatch offer expiry sweep, every 5s).');
});
