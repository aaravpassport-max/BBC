import { pool, withTransaction } from '../../db/pool';
import { Errors } from '../../utils/errors';

const KYC_STEPS = [
  'personal_details',
  'identity_document',
  'driving_license',
  'vehicle_documents',
  'bank_details',
  'vehicle_photos',
  'consent',
] as const;
type KycStep = (typeof KYC_STEPS)[number];

const DOC_TYPE_BY_STEP: Record<string, string | null> = {
  personal_details: null,
  identity_document: 'identity',
  driving_license: 'driving_license',
  vehicle_documents: 'rc', // simplified: a real flow submits rc/insurance/permit/puc as separate calls
  bank_details: 'bank_details',
  vehicle_photos: null,
  consent: null,
};

/**
 * Converts a customer-type account into a driver application (PRD 3.1/3.2).
 * This is the real gap flagged in the previous session — account_type
 * previously defaulted to 'customer' with no path to become a driver.
 * Idempotent: calling this on an already-registered driver is a no-op success
 * rather than an error, since a resumed onboarding flow may retry this step.
 */
export async function registerAsDriver(userId: string): Promise<void> {
  await withTransaction(async (client) => {
    const userResult = await client.query(`SELECT account_type FROM users WHERE id = $1 FOR UPDATE`, [
      userId,
    ]);
    if (userResult.rowCount === 0) {
      throw Errors.notFound('User');
    }

    if (userResult.rows[0].account_type !== 'driver') {
      await client.query(`UPDATE users SET account_type = 'driver' WHERE id = $1`, [userId]);
    }

    const existingProfile = await client.query(`SELECT user_id FROM driver_profiles WHERE user_id = $1`, [
      userId,
    ]);
    if (existingProfile.rowCount === 0) {
      await client.query(
        `INSERT INTO driver_profiles (user_id, kyc_status, training_status) VALUES ($1, 'incomplete', 'not_started')`,
        [userId]
      );
    }
  });
}

/**
 * Submits one KYC step (PRD 3.2). document_url stands in for a real
 * presigned-S3-upload result — this reference implementation accepts a URL
 * string directly rather than handling multipart upload + object storage,
 * which is an infrastructure integration outside what a sandboxed dev
 * environment can meaningfully exercise.
 */
export async function submitKycStep(params: {
  driverId: string;
  step: string;
  fields?: Record<string, unknown>;
  documentUrl?: string;
  expiryDate?: string;
}): Promise<void> {
  const { driverId, step, fields, documentUrl, expiryDate } = params;

  if (!KYC_STEPS.includes(step as KycStep)) {
    throw Errors.validation({ step: `Unknown KYC step: ${step}` });
  }

  const docType = DOC_TYPE_BY_STEP[step];

  await withTransaction(async (client) => {
    const profile = await client.query(`SELECT user_id FROM driver_profiles WHERE user_id = $1 FOR UPDATE`, [
      driverId,
    ]);
    if (profile.rowCount === 0) {
      throw Errors.validation({ driver: 'Call /driver/register before submitting KYC steps.' });
    }

    if (docType) {
      // Versioning: a resubmission supersedes the prior version rather than
      // overwriting it in place (PRD 3.2 edge case — rejection history retained).
      const priorVersion = await client.query(
        `SELECT id, version FROM kyc_documents
         WHERE subject_type = 'driver' AND subject_id = $1 AND doc_type = $2
         ORDER BY version DESC LIMIT 1`,
        [driverId, docType]
      );
      const nextVersion = priorVersion.rowCount && priorVersion.rowCount > 0 ? priorVersion.rows[0].version + 1 : 1;

      const inserted = await client.query(
        `INSERT INTO kyc_documents (subject_type, subject_id, doc_type, status, document_url, manual_entry, expiry_date, version)
         VALUES ('driver', $1, $2, 'pending_review', $3, $4, $5, $6)
         RETURNING id`,
        [driverId, docType, documentUrl || '', JSON.stringify(fields || {}), expiryDate || null, nextVersion]
      );

      if (priorVersion.rowCount && priorVersion.rowCount > 0) {
        await client.query(`UPDATE kyc_documents SET superseded_by = $1 WHERE id = $2`, [
          inserted.rows[0].id,
          priorVersion.rows[0].id,
        ]);
      }
    }

    // overall_status recomputation happens on read (getKycStatus below), not
    // written eagerly here, to avoid two sources of truth drifting apart.
  });
}

export async function getKycStatus(driverId: string) {
  const docsResult = await pool.query(
    `SELECT DISTINCT ON (doc_type) doc_type, status, rejection_reason
     FROM kyc_documents
     WHERE subject_type = 'driver' AND subject_id = $1
     ORDER BY doc_type, version DESC`,
    [driverId]
  );

  const submittedDocTypes = new Set(docsResult.rows.map((r) => r.doc_type));
  const requiredDocTypes = Object.values(DOC_TYPE_BY_STEP).filter((v): v is string => v !== null);

  const allRequiredSubmitted = requiredDocTypes.every((dt) => submittedDocTypes.has(dt));
  const anyRejected = docsResult.rows.some((r) => r.status === 'rejected');
  const allApproved = docsResult.rows.every((r) => r.status === 'approved') && allRequiredSubmitted;

  let overallStatus: string;
  if (!allRequiredSubmitted) overallStatus = 'incomplete';
  else if (anyRejected) overallStatus = 'rejected';
  else if (allApproved) overallStatus = 'approved';
  else overallStatus = 'pending_review';

  // Sync the driver_profiles cache column so eligibility checks (driver.service)
  // stay fast without re-deriving this on every dispatch candidate scan.
  await pool.query(`UPDATE driver_profiles SET kyc_status = $1 WHERE user_id = $2`, [
    overallStatus,
    driverId,
  ]);

  return {
    overall_status: overallStatus,
    steps: KYC_STEPS.map((step) => {
      const docType = DOC_TYPE_BY_STEP[step];
      if (!docType) return { step, status: 'not_applicable' };
      const doc = docsResult.rows.find((r) => r.doc_type === docType);
      return {
        step,
        status: doc ? doc.status : 'not_submitted',
        rejection_reason: doc?.rejection_reason || null,
      };
    }),
  };
}

/**
 * Admin/reviewer action (PRD Section 7) — approves or rejects a specific
 * document version. Exposed here as a plain function; the route wiring adds
 * the requirePermission('driver', 'kyc_review') gate (PRD Section 22 RBAC).
 */
export async function reviewKycDocument(params: {
  documentId: string;
  reviewerId: string;
  decision: 'approved' | 'rejected';
  rejectionReason?: string;
  rejectionNote?: string;
}): Promise<void> {
  const { documentId, reviewerId, decision, rejectionReason, rejectionNote } = params;

  await pool.query(
    `UPDATE kyc_documents
     SET status = $1, rejection_reason = $2, rejection_note = $3, reviewed_by = $4, reviewed_at = now()
     WHERE id = $5`,
    [decision, decision === 'rejected' ? rejectionReason : null, rejectionNote || null, reviewerId, documentId]
  );

  await pool.query(
    `INSERT INTO audit_log (actor_id, actor_type, action, resource_type, resource_id, after_state)
     VALUES ($1, 'user', 'kyc_document.review', 'kyc_document', $2, $3)`,
    [reviewerId, documentId, JSON.stringify({ decision, rejectionReason })]
  );

  const doc = await pool.query(`SELECT driver_id FROM kyc_documents WHERE id = $1`, [documentId]);
  if (doc.rowCount && doc.rowCount > 0) {
    await getKycStatus(doc.rows[0].driver_id);
  }
}

/** Driver-facing document center — latest version per doc type. */
export async function listDriverDocuments(driverId: string) {
  const result = await pool.query(
    `SELECT DISTINCT ON (doc_type)
       id, doc_type, status, document_url, expiry_date, rejection_reason, version, created_at
     FROM kyc_documents
     WHERE subject_type = 'driver' AND subject_id = $1
     ORDER BY doc_type, version DESC`,
    [driverId]
  );
  return result.rows.map((row) => ({
    id: row.id,
    doc_type: row.doc_type,
    status: row.status,
    document_url: row.document_url,
    expiry_date: row.expiry_date,
    rejection_reason: row.rejection_reason,
    version: row.version,
    created_at: row.created_at,
    days_until_expiry:
      row.expiry_date != null
        ? Math.ceil((new Date(row.expiry_date).getTime() - Date.now()) / (24 * 60 * 60 * 1000))
        : null,
  }));
}

/** Admin queue — documents awaiting review, newest first. */
export async function listPendingKycDocuments(limit = 50) {
  const result = await pool.query(
    `SELECT kd.id, kd.driver_id, kd.doc_type, kd.status, kd.created_at, u.phone, u.name
     FROM kyc_documents kd
     JOIN users u ON u.id = kd.driver_id
     WHERE kd.status = 'pending_review'
     ORDER BY kd.created_at ASC
     LIMIT $1`,
    [limit]
  );
  return result.rows;
}
