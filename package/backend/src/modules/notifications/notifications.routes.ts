import { Router } from 'express';
import { z } from 'zod';
import { asyncHandler } from '../../middleware/errorHandler';
import { validateBody } from '../../middleware/validate';
import { requireAuth } from '../../middleware/auth';
import { getPreferences, setPreference, getInbox, registerDeviceToken, unregisterDeviceToken } from './notifications.service';

const setPreferenceSchema = z.object({
  category: z.string(),
  channel: z.string(),
  enabled: z.boolean(),
});

const deviceTokenSchema = z.object({
  platform: z.enum(['android', 'ios', 'web']),
  token: z.string().min(1),
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

notificationsRouter.post(
  '/device-tokens',
  requireAuth,
  validateBody(deviceTokenSchema),
  asyncHandler(async (req, res) => {
    await registerDeviceToken({
      userId: req.user!.userId,
      platform: req.body.platform,
      token: req.body.token,
    });
    res.status(200).json({ registered: true });
  })
);

notificationsRouter.delete(
  '/device-tokens',
  requireAuth,
  validateBody(z.object({ token: z.string().min(1) })),
  asyncHandler(async (req, res) => {
    await unregisterDeviceToken({ userId: req.user!.userId, token: req.body.token });
    res.status(200).json({ removed: true });
  })
);
