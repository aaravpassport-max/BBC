import { Router } from 'express';
import { z } from 'zod';
import { asyncHandler } from '../../middleware/errorHandler';
import { validateBody } from '../../middleware/validate';
import { requireAuth } from '../../middleware/auth';
import { getPreferences, setPreference, getInbox } from './notifications.service';

const setPreferenceSchema = z.object({
  category: z.string(),
  channel: z.string(),
  enabled: z.boolean(),
});

export const notificationsRouter = Router();

// PRD 16A.1
notificationsRouter.get(
  '/preferences',
  requireAuth,
  asyncHandler(async (req, res) => {
    const prefs = await getPreferences(req.user!.userId);
    res.status(200).json(prefs);
  })
);

notificationsRouter.put(
  '/preferences',
  requireAuth,
  validateBody(setPreferenceSchema),
  asyncHandler(async (req, res) => {
    const { category, channel, enabled } = req.body;
    await setPreference({ userId: req.user!.userId, category, channel, enabled });
    res.status(200).json({ updated: true });
  })
);

// PRD Screens 47-48
notificationsRouter.get(
  '/inbox',
  requireAuth,
  asyncHandler(async (req, res) => {
    const inbox = await getInbox(req.user!.userId);
    res.status(200).json(inbox);
  })
);
