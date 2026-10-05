import { Router } from 'express';
import { z } from 'zod';
import { asyncHandler } from '../../middleware/errorHandler';
import { validateBody } from '../../middleware/validate';
import { requireAuth } from '../../middleware/auth';
import { getTrainingStatus, updateVideoProgress, submitQuiz } from './training.service';

const progressSchema = z.object({ watched_pct: z.number().min(0).max(100) });
const quizSubmitSchema = z.object({ answers: z.array(z.number().int().min(0)) });

export const trainingRouter = Router();

// PRD 3.2: GET /v1/driver/training/modules
trainingRouter.get(
  '/modules',
  requireAuth,
  asyncHandler(async (req, res) => {
    const status = await getTrainingStatus(req.user!.userId);
    res.status(200).json(status);
  })
);

trainingRouter.post(
  '/platform_basics/progress',
  requireAuth,
  validateBody(progressSchema),
  asyncHandler(async (req, res) => {
    const status = await updateVideoProgress(req.user!.userId, req.body.watched_pct);
    res.status(200).json(status);
  })
);

// PRD 3.2: POST /v1/driver/training/{module}/quiz-submit
trainingRouter.post(
  '/platform_basics/quiz-submit',
  requireAuth,
  validateBody(quizSubmitSchema),
  asyncHandler(async (req, res) => {
    const result = await submitQuiz(req.user!.userId, req.body.answers);
    res.status(200).json(result);
  })
);
