import { pool } from '../../db/pool';
import { Errors } from '../../utils/errors';

const MODULE_NAME = 'platform_basics';
const MIN_WATCH_PCT_FOR_QUIZ = 90; // PRD: "must-watch-to-completion, not skippable past a config minimum watch percentage"
const PASS_THRESHOLD_PCT = 80;
const MAX_ATTEMPTS = 3;
const RETAKE_COOLDOWN_MINUTES = 30;

// A fixed, small quiz — matching the PRD's explicit note (line 735) that
// gamified/expanded training is out of v1 scope; this is the minimum real
// gate the acceptance criteria require, not a full CMS-managed quiz system.
interface QuizQuestion {
  question: string;
  options: string[];
  correctIndex: number;
}

const QUIZ: QuizQuestion[] = [
  {
    question: 'What must you always verify before starting a pickup?',
    options: ["The customer's pickup OTP", "The customer's star rating", 'The traffic forecast'],
    correctIndex: 0,
  },
  {
    question: "If a customer asks you to exceed the vehicle's stated weight capacity, you should:",
    options: ['Do it if they tip well', 'Decline and explain the safety limit', 'Estimate and proceed if it looks close'],
    correctIndex: 1,
  },
  {
    question: 'When can you mark a drop as complete?',
    options: [
      'As soon as you arrive at the address',
      'Only after entering the correct drop OTP or required proof',
      'Whenever the app lets you',
    ],
    correctIndex: 1,
  },
  {
    question: 'What should you do if you feel unsafe during a trip?',
    options: ['Wait until the trip ends', 'Use the SOS feature immediately', "Call the customer's emergency contact yourself"],
    correctIndex: 1,
  },
  {
    question: "Can you share a customer's pickup OTP with anyone else?",
    options: ['Yes, if they ask nicely', 'No — it exists to confirm you are genuinely present', 'Only with other drivers'],
    correctIndex: 1,
  },
];

function getModuleRow(driverId: string) {
  return pool.query(`SELECT * FROM driver_training_progress WHERE driver_id = $1 AND module = $2`, [
    driverId,
    MODULE_NAME,
  ]);
}

async function ensureRow(driverId: string) {
  const existing = await getModuleRow(driverId);
  if (existing.rowCount && existing.rowCount > 0) return existing.rows[0];
  const created = await pool.query(
    `INSERT INTO driver_training_progress (driver_id, module) VALUES ($1, $2) RETURNING *`,
    [driverId, MODULE_NAME]
  );
  return created.rows[0];
}

export async function getTrainingStatus(driverId: string) {
  const row = await ensureRow(driverId);
  const canRetakeAt =
    row.status === 'quiz_available' && row.quiz_attempts > 0 && row.last_attempt_at
      ? new Date(new Date(row.last_attempt_at).getTime() + RETAKE_COOLDOWN_MINUTES * 60 * 1000).toISOString()
      : null;

  return {
    module: row.module,
    status: row.status,
    video_watched_pct: row.video_watched_pct,
    quiz_attempts: row.quiz_attempts,
    max_attempts: MAX_ATTEMPTS,
    can_retake_at: canRetakeAt,
    quiz_questions:
      row.status === 'quiz_available' ? QUIZ.map((q) => ({ question: q.question, options: q.options })) : undefined,
  };
}

/**
 * Updates video watch progress (PRD 3.2: "resumable, doesn't restart from
 * zero if the app is closed mid-video"). Monotonically increasing — a
 * client reporting a lower percentage than already recorded never regresses
 * progress (e.g. a stale/out-of-order request arriving after a further-
 * along one).
 */
export async function updateVideoProgress(driverId: string, watchedPct: number) {
  const row = await ensureRow(driverId);
  if (row.status === 'passed' || row.status === 'locked_for_review') {
    return getTrainingStatus(driverId);
  }

  const newPct = Math.max(row.video_watched_pct, watchedPct);
  const newStatus = newPct >= MIN_WATCH_PCT_FOR_QUIZ ? 'quiz_available' : 'in_progress';

  await pool.query(
    `UPDATE driver_training_progress SET video_watched_pct = $1, status = $2, updated_at = now()
     WHERE driver_id = $3 AND module = $4`,
    [newPct, newStatus, driverId, MODULE_NAME]
  );
  return getTrainingStatus(driverId);
}

/**
 * Grades a quiz submission (PRD 3.2 acceptance criterion: training
 * completion is as hard a gate on APPROVED as document KYC — passing here
 * is what actually sets driver_profiles.training_status = 'passed', the
 * same column dispatch eligibility checks). On repeated failure beyond
 * MAX_ATTEMPTS, routes to manual review rather than a permanent dead end
 * (PRD: "routed to a manual review/support contact rather than permanently
 * locked out with no path forward").
 */
export async function submitQuiz(
  driverId: string,
  answers: number[]
): Promise<{ passed: boolean; scorePct: number; status: string }> {
  const row = await ensureRow(driverId);

  if (row.status === 'passed') {
    return { passed: true, scorePct: 100, status: 'passed' };
  }
  if (row.status === 'locked_for_review') {
    throw Errors.validation({
      training: 'Maximum quiz attempts reached. This has been routed to manual review — contact support.',
    });
  }
  if (row.status !== 'quiz_available') {
    throw Errors.validation({
      training: `Complete the training video (${MIN_WATCH_PCT_FOR_QUIZ}%+) before taking the quiz.`,
    });
  }
  if (row.quiz_attempts > 0 && row.last_attempt_at) {
    const cooldownEnds = new Date(row.last_attempt_at).getTime() + RETAKE_COOLDOWN_MINUTES * 60 * 1000;
    if (Date.now() < cooldownEnds) {
      throw Errors.validation({
        training: `Please wait before retaking the quiz. Available again at ${new Date(cooldownEnds).toISOString()}.`,
      });
    }
  }
  if (answers.length !== QUIZ.length) {
    throw Errors.validation({ answers: `Expected ${QUIZ.length} answers, got ${answers.length}.` });
  }

  const correctCount = QUIZ.reduce((count, q, i) => count + (answers[i] === q.correctIndex ? 1 : 0), 0);
  const scorePct = Math.round((correctCount / QUIZ.length) * 100);
  const passed = scorePct >= PASS_THRESHOLD_PCT;
  const newAttempts = row.quiz_attempts + 1;

  if (passed) {
    await pool.query(
      `UPDATE driver_training_progress SET status = 'passed', quiz_attempts = $1, last_attempt_at = now(), passed_at = now(), updated_at = now()
       WHERE driver_id = $2 AND module = $3`,
      [newAttempts, driverId, MODULE_NAME]
    );
    await pool.query(`UPDATE driver_profiles SET training_status = 'passed' WHERE user_id = $1`, [driverId]);
    return { passed: true, scorePct, status: 'passed' };
  }

  const nextStatus = newAttempts >= MAX_ATTEMPTS ? 'locked_for_review' : 'quiz_available';
  await pool.query(
    `UPDATE driver_training_progress SET status = $1, quiz_attempts = $2, last_attempt_at = now(), updated_at = now()
     WHERE driver_id = $3 AND module = $4`,
    [nextStatus, newAttempts, driverId, MODULE_NAME]
  );
  return { passed: false, scorePct, status: nextStatus };
}
