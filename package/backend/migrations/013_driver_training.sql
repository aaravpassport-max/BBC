-- Migration 013: Driver training module (PRD 3.2 "Training Module
-- (video/quiz)") — a real, previously-flagged gap: training_status existed
-- as a column since migration 002, but nothing ever moved it out of
-- 'not_started' except direct SQL in tests/dev. This adds the actual
-- video-progress + quiz-attempt tracking behind it.

BEGIN;

CREATE TABLE driver_training_progress (
    id                  UUID PRIMARY KEY DEFAULT gen_random_uuid(),
    driver_id           UUID NOT NULL REFERENCES users(id),
    module              VARCHAR(50) NOT NULL DEFAULT 'platform_basics',
    video_watched_pct   INTEGER NOT NULL DEFAULT 0 CHECK (video_watched_pct BETWEEN 0 AND 100),
    quiz_attempts       INTEGER NOT NULL DEFAULT 0,
    status              VARCHAR(20) NOT NULL DEFAULT 'not_started'
                        CHECK (status IN ('not_started', 'in_progress', 'quiz_available', 'passed', 'locked_for_review')),
    last_attempt_at     TIMESTAMPTZ,
    passed_at           TIMESTAMPTZ,
    created_at          TIMESTAMPTZ NOT NULL DEFAULT now(),
    updated_at          TIMESTAMPTZ NOT NULL DEFAULT now(),
    UNIQUE (driver_id, module)
);

COMMIT;

-- ============================== DOWN ==============================
-- BEGIN;
-- DROP TABLE IF EXISTS driver_training_progress;
-- COMMIT;
