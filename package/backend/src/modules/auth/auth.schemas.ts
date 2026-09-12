import { z } from 'zod';

export const requestOtpSchema = z.object({
  phone: z
    .string()
    .regex(/^[0-9]{10}$/, 'Enter a valid 10-digit mobile number'),
  country_code: z.string().min(2).max(5),
  device_id: z.string().min(1),
  app_version: z.string().min(1),
});

export const verifyOtpSchema = z.object({
  otp_id: z.string().uuid(),
  code: z.string().regex(/^[0-9]{6}$/, 'Enter the 6-digit code'),
  device_id: z.string().min(1),
});

export const demoLoginSchema = z.object({
  phone: z
    .string()
    .regex(/^[0-9]{10}$/, 'Enter a valid 10-digit mobile number'),
  country_code: z.string().min(2).max(5),
  device_id: z.string().min(1),
});
