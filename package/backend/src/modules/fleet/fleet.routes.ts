import { Router } from 'express';
import { z } from 'zod';
import { asyncHandler } from '../../middleware/errorHandler';
import { validateBody } from '../../middleware/validate';
import { requireAuth } from '../../middleware/auth';
import {
  reassignVehicle,
  getFleetVehicles,
  getFleetDrivers,
  addDriverToFleet,
  removeDriverFromFleet,
  getFleetDriverDetail,
  getFleetEarningsSummary,
} from './fleet.service';

const reassignSchema = z.object({ new_driver_id: z.string().uuid() });
const addDriverSchema = z.object({ driver_phone: z.string().min(1) });

export const fleetRouter = Router();

// PRD Section 13
fleetRouter.get(
  '/vehicles',
  requireAuth,
  asyncHandler(async (req, res) => {
    const vehicles = await getFleetVehicles(req.user!.userId);
    res.status(200).json(vehicles);
  })
);

// PRD 13A.1
fleetRouter.post(
  '/vehicles/:id/reassign',
  requireAuth,
  validateBody(reassignSchema),
  asyncHandler(async (req, res) => {
    const result = await reassignVehicle({
      vehicleId: req.params.id as string,
      newDriverId: req.body.new_driver_id,
      ownerId: req.user!.userId,
    });
    res.status(200).json(result);
  })
);

// P1 gap-analysis item — Fleet/Owner experience.
fleetRouter.get(
  '/drivers',
  requireAuth,
  asyncHandler(async (req, res) => {
    const drivers = await getFleetDrivers(req.user!.userId);
    res.status(200).json(drivers);
  })
);

fleetRouter.post(
  '/drivers',
  requireAuth,
  validateBody(addDriverSchema),
  asyncHandler(async (req, res) => {
    const result = await addDriverToFleet({ ownerId: req.user!.userId, driverPhone: req.body.driver_phone });
    res.status(201).json(result);
  })
);

fleetRouter.delete(
  '/drivers/:driverId',
  requireAuth,
  asyncHandler(async (req, res) => {
    await removeDriverFromFleet({ ownerId: req.user!.userId, driverId: req.params.driverId as string });
    res.status(200).json({ removed: true });
  })
);

fleetRouter.get(
  '/drivers/:driverId',
  requireAuth,
  asyncHandler(async (req, res) => {
    const detail = await getFleetDriverDetail(req.user!.userId, req.params.driverId as string);
    res.status(200).json(detail);
  })
);

fleetRouter.get(
  '/earnings',
  requireAuth,
  asyncHandler(async (req, res) => {
    const summary = await getFleetEarningsSummary(req.user!.userId);
    res.status(200).json(summary);
  })
);
