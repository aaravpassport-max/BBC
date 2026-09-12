import request from 'supertest';
import { createApp } from '../../../app';
import { pool } from '../../../db/pool';
import { loginAsNewUser } from '../../../test-utils/helpers';

const app = createApp();

afterAll(async () => {
  await pool.end();
});

describe('Driver training: video progress (PRD 3.2)', () => {
  it('starts at not_started with 0% watched', async () => {
    const { accessToken } = await loginAsNewUser(app);
    const res = await request(app).get('/v1/driver/training/modules').set('Authorization', `Bearer ${accessToken}`);
    expect(res.status).toBe(200);
    expect(res.body.status).toBe('not_started');
    expect(res.body.video_watched_pct).toBe(0);
  });

  it('progress is resumable and monotonically increasing — a lower report never regresses it', async () => {
    const { accessToken } = await loginAsNewUser(app);
    await request(app)
      .post('/v1/driver/training/platform_basics/progress')
      .set('Authorization', `Bearer ${accessToken}`)
      .send({ watched_pct: 60 });
    const regressed = await request(app)
      .post('/v1/driver/training/platform_basics/progress')
      .set('Authorization', `Bearer ${accessToken}`)
      .send({ watched_pct: 20 });
    expect(regressed.body.video_watched_pct).toBe(60);
  });

  it('reaching the minimum watch threshold unlocks the quiz, below it does not', async () => {
    const { accessToken } = await loginAsNewUser(app);
    const below = await request(app)
      .post('/v1/driver/training/platform_basics/progress')
      .set('Authorization', `Bearer ${accessToken}`)
      .send({ watched_pct: 50 });
    expect(below.body.status).toBe('in_progress');
    expect(below.body.quiz_questions).toBeUndefined();

    const above = await request(app)
      .post('/v1/driver/training/platform_basics/progress')
      .set('Authorization', `Bearer ${accessToken}`)
      .send({ watched_pct: 95 });
    expect(above.body.status).toBe('quiz_available');
    expect(above.body.quiz_questions.length).toBe(5);
  });

  it('SECURITY: the quiz questions returned to the client never include the correct answer index', async () => {
    const { accessToken } = await loginAsNewUser(app);
    await request(app)
      .post('/v1/driver/training/platform_basics/progress')
      .set('Authorization', `Bearer ${accessToken}`)
      .send({ watched_pct: 100 });
    const res = await request(app).get('/v1/driver/training/modules').set('Authorization', `Bearer ${accessToken}`);
    for (const q of res.body.quiz_questions) {
      expect(q.correctIndex).toBeUndefined();
      expect(Object.keys(q).sort()).toEqual(['options', 'question']);
    }
  });
});

describe('Driver training: quiz gate (PRD 3.2 hard acceptance criterion)', () => {
  it('cannot submit the quiz before watching enough of the video', async () => {
    const { accessToken } = await loginAsNewUser(app);
    const res = await request(app)
      .post('/v1/driver/training/platform_basics/quiz-submit')
      .set('Authorization', `Bearer ${accessToken}`)
      .send({ answers: [0, 1, 1, 1, 1] });
    expect(res.status).toBe(400);
  });

  it('registering for KYC first, then passing training, correctly sets the eligibility-gate column', async () => {
    const { accessToken, userId } = await loginAsNewUser(app);
    await request(app).post('/v1/driver/kyc/register').set('Authorization', `Bearer ${accessToken}`);
    await request(app)
      .post('/v1/driver/training/platform_basics/progress')
      .set('Authorization', `Bearer ${accessToken}`)
      .send({ watched_pct: 100 });
    const submit = await request(app)
      .post('/v1/driver/training/platform_basics/quiz-submit')
      .set('Authorization', `Bearer ${accessToken}`)
      .send({ answers: [0, 1, 1, 1, 1] });
    expect(submit.status).toBe(200);
    expect(submit.body.passed).toBe(true);
    expect(submit.body.scorePct).toBe(100);

    const profileRow = await pool.query('SELECT training_status FROM driver_profiles WHERE user_id = $1', [userId]);
    expect(profileRow.rows[0].training_status).toBe('passed');
  });

  it('a failing score does not pass, and the attempt count increments', async () => {
    const { accessToken } = await loginAsNewUser(app);
    await request(app)
      .post('/v1/driver/training/platform_basics/progress')
      .set('Authorization', `Bearer ${accessToken}`)
      .send({ watched_pct: 100 });

    const res = await request(app)
      .post('/v1/driver/training/platform_basics/quiz-submit')
      .set('Authorization', `Bearer ${accessToken}`)
      .send({ answers: [1, 0, 0, 0, 0] }); // all wrong
    expect(res.body.passed).toBe(false);
    expect(res.body.scorePct).toBe(0);

    const status = await request(app).get('/v1/driver/training/modules').set('Authorization', `Bearer ${accessToken}`);
    expect(status.body.quiz_attempts).toBe(1);
    expect(status.body.can_retake_at).toBeTruthy();
  });

  it('is enforced by a retake cooldown — an immediate second attempt is blocked', async () => {
    const { accessToken } = await loginAsNewUser(app);
    await request(app)
      .post('/v1/driver/training/platform_basics/progress')
      .set('Authorization', `Bearer ${accessToken}`)
      .send({ watched_pct: 100 });
    await request(app)
      .post('/v1/driver/training/platform_basics/quiz-submit')
      .set('Authorization', `Bearer ${accessToken}`)
      .send({ answers: [1, 0, 0, 0, 0] });

    const immediateRetry = await request(app)
      .post('/v1/driver/training/platform_basics/quiz-submit')
      .set('Authorization', `Bearer ${accessToken}`)
      .send({ answers: [0, 1, 1, 1, 1] });
    expect(immediateRetry.status).toBe(400);
  });

  it('routes to manual review after exhausting max attempts, rather than a permanent dead end with no path forward', async () => {
    const { accessToken, userId } = await loginAsNewUser(app);
    await request(app)
      .post('/v1/driver/training/platform_basics/progress')
      .set('Authorization', `Bearer ${accessToken}`)
      .send({ watched_pct: 100 });

    for (let i = 0; i < 3; i++) {
      await pool.query(
        `UPDATE driver_training_progress SET last_attempt_at = now() - interval '1 hour' WHERE driver_id = $1`,
        [userId]
      );
      await request(app)
        .post('/v1/driver/training/platform_basics/quiz-submit')
        .set('Authorization', `Bearer ${accessToken}`)
        .send({ answers: [1, 0, 0, 0, 0] });
    }

    const status = await request(app).get('/v1/driver/training/modules').set('Authorization', `Bearer ${accessToken}`);
    expect(status.body.status).toBe('locked_for_review');

    await pool.query(
      `UPDATE driver_training_progress SET last_attempt_at = now() - interval '1 hour' WHERE driver_id = $1`,
      [userId]
    );
    const retryAfterLock = await request(app)
      .post('/v1/driver/training/platform_basics/quiz-submit')
      .set('Authorization', `Bearer ${accessToken}`)
      .send({ answers: [0, 1, 1, 1, 1] });
    expect(retryAfterLock.status).toBe(400);
    expect(retryAfterLock.body.error.details.training).toMatch(/manual review/);
  });

  it('rejects a wrong number of answers', async () => {
    const { accessToken } = await loginAsNewUser(app);
    await request(app)
      .post('/v1/driver/training/platform_basics/progress')
      .set('Authorization', `Bearer ${accessToken}`)
      .send({ watched_pct: 100 });
    const res = await request(app)
      .post('/v1/driver/training/platform_basics/quiz-submit')
      .set('Authorization', `Bearer ${accessToken}`)
      .send({ answers: [0, 1] });
    expect(res.status).toBe(400);
  });
});
