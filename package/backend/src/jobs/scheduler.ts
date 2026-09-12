import { pool } from '../db/pool';
import { sweepExpiredOffers, sweepScheduledBookings } from '../modules/driver/dispatch.service';
import { sweepUnacknowledgedSos } from '../modules/ops/sos.service';

/**
 * Wraps a job function with the background_job_runs monitoring table
 * (migration 005 / PRD Section 25-26): every run is recorded with a status,
 * so an ops dashboard (or a simple query) can alert if a job's last-successful-
 * run is older than its expected cadence — never a silently-failed cron with
 * no signal, per the PRD's explicit reliability requirement.
 */
async function runMonitoredJob(jobName: string, fn: () => Promise<number>): Promise<void> {
  const runResult = await pool.query(
    `INSERT INTO background_job_runs (job_name, status) VALUES ($1, 'running') RETURNING id`,
    [jobName]
  );
  const runId = runResult.rows[0].id;

  try {
    const rowsAffected = await fn();
    await pool.query(
      `UPDATE background_job_runs SET status = 'succeeded', finished_at = now(), rows_affected = $1 WHERE id = $2`,
      [rowsAffected, runId]
    );
  } catch (err) {
    const message = err instanceof Error ? err.message : String(err);
    await pool.query(
      `UPDATE background_job_runs SET status = 'failed', finished_at = now(), error_detail = $1 WHERE id = $2`,
      [message, runId]
    );
    console.error(`Background job ${jobName} failed:`, err);
  }
}

let sweepIntervalHandle: NodeJS.Timeout | null = null;
let sosSweepIntervalHandle: NodeJS.Timeout | null = null;
let scheduledBookingSweepIntervalHandle: NodeJS.Timeout | null = null;

/**
 * Starts the recurring jobs this reference backend actually needs running:
 * - dispatch_offer_expiry_sweep: catches offers whose 15s countdown (PRD 3.3)
 *   elapsed with no accept/decline — expires them and re-triggers dispatch
 *   for the next candidate (PRD Section 4), since a driver simply not
 *   responding must not silently strand a booking in SEARCHING forever.
 * - sos_auto_escalation_sweep: catches a triggered SOS with no operator
 *   acknowledgment within the PRD's hard threshold (30s, PRD 10A.1) — this
 *   is the single highest-priority job in the platform per the PRD's own
 *   framing ("this is the single highest-priority screen in the entire
 *   platform"), so it runs on the shortest interval of any job here.
 * - scheduled_booking_dispatch_sweep: catches a scheduled (future-dated)
 *   booking whose real dispatch window has arrived (P1 gap-analysis item)
 *   — without this, a booking made "for 3pm tomorrow" would sit in
 *   status='scheduled' forever, since nothing else ever transitions it.
 *
 * Interval is short (5s) to keep the offer-timeout SLA tight in this dev
 * environment; production would tune this against real traffic/DB load.
 */
export function startBackgroundJobs(): void {
  sweepIntervalHandle = setInterval(() => {
    runMonitoredJob('dispatch_offer_expiry_sweep', sweepExpiredOffers).catch((err) => {
      console.error('Unexpected error in job scheduler:', err);
    });
  }, 5000);

  sosSweepIntervalHandle = setInterval(() => {
    runMonitoredJob('sos_auto_escalation_sweep', sweepUnacknowledgedSos).catch((err) => {
      console.error('Unexpected error in job scheduler:', err);
    });
  }, 3000);

  // A 60s interval is appropriate here — unlike the two jobs above, which
  // guard tight real-time SLAs (15s offer countdown, 30s SOS threshold),
  // this one only needs to notice a scheduled booking within its own
  // 15-minute dispatch lead time, so a full minute of slack costs nothing
  // real while meaningfully reducing load versus polling every 5s.
  scheduledBookingSweepIntervalHandle = setInterval(() => {
    runMonitoredJob('scheduled_booking_dispatch_sweep', sweepScheduledBookings).catch((err) => {
      console.error('Unexpected error in job scheduler:', err);
    });
  }, 60000);
}

export function stopBackgroundJobs(): void {
  if (sweepIntervalHandle) {
    clearInterval(sweepIntervalHandle);
    sweepIntervalHandle = null;
  }
  if (sosSweepIntervalHandle) {
    clearInterval(sosSweepIntervalHandle);
    sosSweepIntervalHandle = null;
  }
  if (scheduledBookingSweepIntervalHandle) {
    clearInterval(scheduledBookingSweepIntervalHandle);
    scheduledBookingSweepIntervalHandle = null;
  }
}
