import { Router } from 'express';
import { asyncHandler } from '../../middleware/errorHandler';
import { requireAuth } from '../../middleware/auth';
import { Errors } from '../../utils/errors';
import * as places from './places.provider';
import { checkServiceability } from './geo.service';
import { fetchDrivingRoute } from './route.service';

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

geoRouter.get(
  '/route',
  requireAuth,
  asyncHandler(async (req, res) => {
    const raw = typeof req.query.waypoints === 'string' ? req.query.waypoints : '';
    if (!raw) {
      throw Errors.validation({ waypoints: 'waypoints query param is required (lat,lng;lat,lng;...).' });
    }

    const waypoints = raw.split(';').map((pair, i) => {
      const [latStr, lngStr] = pair.split(',');
      const lat = parseFloat(latStr);
      const lng = parseFloat(lngStr);
      if (Number.isNaN(lat) || Number.isNaN(lng)) {
        throw Errors.validation({ waypoints: `Invalid coordinate at position ${i + 1}.` });
      }
      return { lat, lng };
    });

    if (waypoints.length < 2) {
      throw Errors.validation({ waypoints: 'At least two waypoints are required.' });
    }
    if (waypoints.length > 10) {
      throw Errors.validation({ waypoints: 'At most 10 waypoints are supported.' });
    }

    const route = await fetchDrivingRoute(waypoints);
    if (!route) {
      throw Errors.validation({ route: 'Could not compute a driving route for these waypoints.' });
    }

    res.status(200).json(route);
  })
);
