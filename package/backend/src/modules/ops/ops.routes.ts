import { Router } from 'express';
import { z } from 'zod';
import { asyncHandler } from '../../middleware/errorHandler';
import { validateBody } from '../../middleware/validate';
import { requireAuth, requirePermission } from '../../middleware/auth';
import { triggerSos, getSosQueue, acknowledgeSos, resolveSos, escalateSos } from './sos.service';
import { getDispatchLog, forceAssignDriver } from './dispatch-monitor.service';
import { listLiveDrivers } from './live-map.service';

const triggerSchema = z.object({
  booking_id: z.string().uuid(),
  lat: z.number().optional(),
  lng: z.number().optional(),
});

const resolveSchema = z.object({
  outcome_tag: z.enum(['false_alarm', 'resolved_safe', 'escalated_to_authorities', 'other']),
  resolution_note: z.string().min(1),
});

const forceAssignSchema = z.object({ driver_id: z.string().uuid() });

export const opsRouter = Router();

// PRD 10A.1 — any authenticated user can trigger SOS from their own active
// booking (the service layer verifies they're actually a participant on
// it); this is not an ops-permission-gated action, unlike everything else
// in this router.
opsRouter.post(
  '/sos/trigger',
  requireAuth,
  validateBody(triggerSchema),
  asyncHandler(async (req, res) => {
    const { booking_id, lat, lng } = req.body;
    const result = await triggerSos({ bookingId: booking_id, triggeredBy: req.user!.userId, lat, lng });
    res.status(201).json(result);
  })
);

opsRouter.get(
  '/sos/queue',
  requireAuth,
  requirePermission('ops', 'sos_respond'),
  asyncHandler(async (_req, res) => {
    res.status(200).json(await getSosQueue());
  })
);

opsRouter.post(
  '/sos/:id/acknowledge',
  requireAuth,
  requirePermission('ops', 'sos_respond'),
  asyncHandler(async (req, res) => {
    await acknowledgeSos(req.params.id as string, req.user!.userId);
    res.status(200).json({ acknowledged: true });
  })
);

opsRouter.post(
  '/sos/:id/resolve',
  requireAuth,
  requirePermission('ops', 'sos_respond'),
  validateBody(resolveSchema),
  asyncHandler(async (req, res) => {
    const { outcome_tag, resolution_note } = req.body;
    await resolveSos({
      id: req.params.id as string,
      operatorId: req.user!.userId,
      outcomeTag: outcome_tag,
      resolutionNote: resolution_note,
    });
    res.status(200).json({ resolved: true });
  })
);

// PRD 10A.1: "ops.sos.escalate for secondary-tier actions" — a deliberately
// separate, smaller-trust permission from ops.sos_respond above.
opsRouter.post(
  '/sos/:id/escalate',
  requireAuth,
  requirePermission('ops', 'sos_escalate'),
  asyncHandler(async (req, res) => {
    await escalateSos(req.params.id as string, req.user!.userId);
    res.status(200).json({ escalated: true });
  })
);

// PRD A.3 — dispatch inspection/override, distinct permission from SOS
// response since these are different operational responsibilities.
opsRouter.get(
  '/bookings/:id/dispatch-log',
  requireAuth,
  requirePermission('ops', 'dispatch_override'),
  asyncHandler(async (req, res) => {
    res.status(200).json(await getDispatchLog(req.params.id as string));
  })
);

opsRouter.post(
  '/bookings/:id/force-assign',
  requireAuth,
  requirePermission('ops', 'dispatch_override'),
  validateBody(forceAssignSchema),
  asyncHandler(async (req, res) => {
    await forceAssignDriver({
      bookingId: req.params.id as string,
      driverId: req.body.driver_id,
      actorId: req.user!.userId,
    });
    res.status(200).json({ assigned: true });
  })
);

opsRouter.get(
  '/live-map/drivers',
  requireAuth,
  requirePermission('ops', 'dispatch_override'),
  asyncHandler(async (_req, res) => {
    res.status(200).json(await listLiveDrivers());
  })
);
