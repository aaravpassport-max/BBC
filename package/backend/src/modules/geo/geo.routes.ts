import { Router } from 'express';
import { asyncHandler } from '../../middleware/errorHandler';
import { requireAuth } from '../../middleware/auth';
import { Errors } from '../../utils/errors';
import * as places from './places.provider';
import { checkServiceability } from './geo.service';

export const geoRouter = Router();

geoRouter.get(
  '/autocomplete',
  requireAuth,
  asyncHandler(async (req, res) => {
    const q = typeof req.query.q === 'string' ? req.query.q : '';
    const results = await places.autocomplete(q);
    res.status(200).json(results);
  })
);

geoRouter.get(
  '/reverse',
  requireAuth,
  asyncHandler(async (req, res) => {
    const lat = parseFloat(req.query.lat as string);
    const lng = parseFloat(req.query.lng as string);
    const address = await places.reverseGeocode(lat, lng);
    res.status(200).json({ address });
  })
);

geoRouter.get(
  '/serviceability',
  requireAuth,
  asyncHandler(async (req, res) => {
    const lat = parseFloat(req.query.lat as string);
    const lng = parseFloat(req.query.lng as string);
    if (Number.isNaN(lat) || Number.isNaN(lng)) {
      throw Errors.validation({ lat: 'lat and lng are required.' });
    }
    res.status(200).json(await checkServiceability(lat, lng));
  })
);
