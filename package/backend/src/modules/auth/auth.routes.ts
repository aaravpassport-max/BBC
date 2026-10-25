import { Router } from 'express';
import { asyncHandler } from '../../middleware/errorHandler';
import { validateBody } from '../../middleware/validate';
import { requestOtpSchema, verifyOtpSchema, demoLoginSchema } from './auth.schemas';
import { requestOtp, verifyOtp, demoLogin } from './auth.service';

export const authRouter = Router();

// PRD 2.2.1
authRouter.post(
  '/otp/request',
  validateBody(requestOtpSchema),
  asyncHandler(async (req, res) => {
    const { phone, country_code, device_id } = req.body;
    const result = await requestOtp({ phone, countryCode: country_code, deviceId: device_id });
    res.status(202).json({
      otp_id: result.otpId,
      expires_in_seconds: result.expiresInSeconds,
      resend_after_seconds: result.resendAfterSeconds,
    });
  })
);

// PRD 2.2.2
authRouter.post(
  '/otp/verify',
  validateBody(verifyOtpSchema),
  asyncHandler(async (req, res) => {
    const { otp_id, code, device_id } = req.body;
    const result = await verifyOtp({ otpId: otp_id, code, deviceId: device_id });
    res.status(200).json({
      access_token: result.accessToken,
      refresh_token: result.refreshToken,
      is_new_user: result.isNewUser,
      user_id: result.userId,
    });
  })
);

// Demo login for physical-device testing — fixed phone numbers, no OTP expiry.
authRouter.post(
  '/demo/login',
  validateBody(demoLoginSchema),
  asyncHandler(async (req, res) => {
    const { phone, country_code, device_id } = req.body;
    const result = await demoLogin({ phone, countryCode: country_code, deviceId: device_id });
    res.status(200).json({
      access_token: result.accessToken,
      refresh_token: result.refreshToken,
      is_new_user: result.isNewUser,
      user_id: result.userId,
    });
  })
);
