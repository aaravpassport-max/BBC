import request from 'supertest';
import { createApp } from '../../../app';
import { pool } from '../../../db/pool';
import { loginAsNewUser } from '../../../test-utils/helpers';
import { getRoleIdByName } from '../../../test-utils/seed';

const app = createApp();

afterAll(async () => {
  await pool.end();
});

async function grantKycReviewPermission(userId: string) {
  const roleId = await getRoleIdByName('kyc_reviewer');
  await pool.query(
    `INSERT INTO user_roles (user_id, role_id) VALUES ($1, $2) ON CONFLICT DO NOTHING`,
    [userId, roleId]
  );
}

describe('KYC: registration and step submission (PRD 3.1-3.2)', () => {
  it('registering as a driver converts account_type and creates an incomplete profile', async () => {
    const { accessToken, userId } = await loginAsNewUser(app);
    const res = await request(app).post('/v1/driver/kyc/register').set('Authorization', `Bearer ${accessToken}`);
    expect(res.status).toBe(200);

    const user = await pool.query('SELECT account_type FROM users WHERE id = $1', [userId]);
    expect(user.rows[0].account_type).toBe('driver');

    const profile = await pool.query('SELECT kyc_status FROM driver_profiles WHERE user_id = $1', [userId]);
    expect(profile.rows[0].kyc_status).toBe('incomplete');
  });

  it('registering twice is idempotent — does not error or duplicate the profile', async () => {
    const { accessToken, userId } = await loginAsNewUser(app);
    await request(app).post('/v1/driver/kyc/register').set('Authorization', `Bearer ${accessToken}`);
    const second = await request(app).post('/v1/driver/kyc/register').set('Authorization', `Bearer ${accessToken}`);
    expect(second.status).toBe(200);

    const profileCount = await pool.query('SELECT count(*) FROM driver_profiles WHERE user_id = $1', [userId]);
    expect(parseInt(profileCount.rows[0].count, 10)).toBe(1);
  });

  it('status is incomplete until all required documents are submitted, then pending_review', async () => {
    const { accessToken } = await loginAsNewUser(app);
    await request(app).post('/v1/driver/kyc/register').set('Authorization', `Bearer ${accessToken}`);

    let status = await request(app).get('/v1/driver/kyc/status').set('Authorization', `Bearer ${accessToken}`);
    expect(status.body.overall_status).toBe('incomplete');

    for (const step of ['identity_document', 'driving_license', 'vehicle_documents', 'bank_details']) {
      await request(app)
        .post(`/v1/driver/kyc/${step}`)
        .set('Authorization', `Bearer ${accessToken}`)
        .send({ document_url: `s3://docs/${step}.jpg` });
    }

    status = await request(app).get('/v1/driver/kyc/status').set('Authorization', `Bearer ${accessToken}`);
    expect(status.body.overall_status).toBe('pending_review');
  });

  it('rejects submission to an unknown KYC step', async () => {
    const { accessToken } = await loginAsNewUser(app);
    await request(app).post('/v1/driver/kyc/register').set('Authorization', `Bearer ${accessToken}`);
    const res = await request(app)
      .post('/v1/driver/kyc/not_a_real_step')
      .set('Authorization', `Bearer ${accessToken}`)
      .send({ document_url: 'x' });
    expect(res.status).toBe(400);
  });
});

describe('KYC: RBAC-gated review (PRD Section 7, Section 22)', () => {
  it('a driver cannot approve their own document (missing driver.kyc_review permission)', async () => {
    const driver = await loginAsNewUser(app);
    await request(app).post('/v1/driver/kyc/register').set('Authorization', `Bearer ${driver.accessToken}`);
    await request(app)
      .post('/v1/driver/kyc/identity_document')
      .set('Authorization', `Bearer ${driver.accessToken}`)
      .send({ document_url: 's3://docs/id.jpg' });

    const doc = await pool.query(
      `SELECT id FROM kyc_documents WHERE subject_id = $1 AND doc_type = 'identity'`,
      [driver.userId]
    );

    const res = await request(app)
      .post(`/v1/driver/kyc/documents/${doc.rows[0].id}/review`)
      .set('Authorization', `Bearer ${driver.accessToken}`)
      .send({ decision: 'approved' });
    expect(res.status).toBe(403);
  });

  it('a user WITH driver.kyc_review permission can approve, and a rejected doc flips overall status to rejected', async () => {
    const driver = await loginAsNewUser(app);
    await request(app).post('/v1/driver/kyc/register').set('Authorization', `Bearer ${driver.accessToken}`);
    for (const step of ['identity_document', 'driving_license', 'vehicle_documents', 'bank_details']) {
      await request(app)
        .post(`/v1/driver/kyc/${step}`)
        .set('Authorization', `Bearer ${driver.accessToken}`)
        .send({ document_url: `s3://docs/${step}.jpg` });
    }

    const reviewer = await loginAsNewUser(app);
    await grantKycReviewPermission(reviewer.userId);

    const docs = await pool.query(`SELECT id, doc_type FROM kyc_documents WHERE subject_id = $1`, [driver.userId]);
    for (const doc of docs.rows) {
      const decision = doc.doc_type === 'driving_license' ? 'rejected' : 'approved';
      const res = await request(app)
        .post(`/v1/driver/kyc/documents/${doc.id}/review`)
        .set('Authorization', `Bearer ${reviewer.accessToken}`)
        .send({ decision, rejection_reason: decision === 'rejected' ? 'DOC_BLURRY' : undefined });
      expect(res.status).toBe(200);
    }

    const status = await request(app)
      .get('/v1/driver/kyc/status')
      .set('Authorization', `Bearer ${driver.accessToken}`);
    expect(status.body.overall_status).toBe('rejected');
    const dlStep = status.body.steps.find((s: { step: string }) => s.step === 'driving_license');
    expect(dlStep.status).toBe('rejected');
    expect(dlStep.rejection_reason).toBe('DOC_BLURRY');
  });
});

describe('KYC integration: eligibility gate reflects review outcome (PRD 3.2 acceptance criteria, cross-module)', () => {
  it('a driver with a rejected document cannot go online, even though other documents were approved', async () => {
    const driver = await loginAsNewUser(app);
    await request(app).post('/v1/driver/kyc/register').set('Authorization', `Bearer ${driver.accessToken}`);
    for (const step of ['identity_document', 'driving_license', 'vehicle_documents', 'bank_details']) {
      await request(app)
        .post(`/v1/driver/kyc/${step}`)
        .set('Authorization', `Bearer ${driver.accessToken}`)
        .send({ document_url: `s3://docs/${step}.jpg` });
    }

    const reviewer = await loginAsNewUser(app);
    await grantKycReviewPermission(reviewer.userId);
    const docs = await pool.query(`SELECT id, doc_type FROM kyc_documents WHERE subject_id = $1`, [driver.userId]);
    for (const doc of docs.rows) {
      const decision = doc.doc_type === 'bank_details' ? 'rejected' : 'approved';
      await request(app)
        .post(`/v1/driver/kyc/documents/${doc.id}/review`)
        .set('Authorization', `Bearer ${reviewer.accessToken}`)
        .send({ decision, rejection_reason: decision === 'rejected' ? 'OTHER' : undefined });
    }

    // Trigger the kyc_status cache sync (getKycStatus recomputes and writes it).
    await request(app).get('/v1/driver/kyc/status').set('Authorization', `Bearer ${driver.accessToken}`);

    const onlineRes = await request(app)
      .post('/v1/driver/status')
      .set('Authorization', `Bearer ${driver.accessToken}`)
      .send({ online: true });
    expect(onlineRes.status).toBe(403);
    expect(onlineRes.body.error.code).toBe('DRIVER_INELIGIBLE');
  });

  it('a fully-approved driver CAN go online', async () => {
    const driver = await loginAsNewUser(app);
    await request(app).post('/v1/driver/kyc/register').set('Authorization', `Bearer ${driver.accessToken}`);
    for (const step of ['identity_document', 'driving_license', 'vehicle_documents', 'bank_details']) {
      await request(app)
        .post(`/v1/driver/kyc/${step}`)
        .set('Authorization', `Bearer ${driver.accessToken}`)
        .send({ document_url: `s3://docs/${step}.jpg` });
    }

    const reviewer = await loginAsNewUser(app);
    await grantKycReviewPermission(reviewer.userId);
    const docs = await pool.query(`SELECT id FROM kyc_documents WHERE subject_id = $1`, [driver.userId]);
    for (const doc of docs.rows) {
      await request(app)
        .post(`/v1/driver/kyc/documents/${doc.id}/review`)
        .set('Authorization', `Bearer ${reviewer.accessToken}`)
        .send({ decision: 'approved' });
    }
    await request(app).get('/v1/driver/kyc/status').set('Authorization', `Bearer ${driver.accessToken}`);

    // Also needs training passed and a location ping — set training directly
    // (no training-submission endpoint exists yet, PRD Section A.2 gap noted
    // previously) and give a current position so the online-toggle's own
    // internal checks (eligibility + implicit location requirement) both pass.
    await pool.query(`UPDATE driver_profiles SET training_status = 'passed' WHERE user_id = $1`, [driver.userId]);

    const onlineRes = await request(app)
      .post('/v1/driver/status')
      .set('Authorization', `Bearer ${driver.accessToken}`)
      .send({ online: true });
    expect(onlineRes.status).toBe(200);
    expect(onlineRes.body.online).toBe(true);
  });
});
