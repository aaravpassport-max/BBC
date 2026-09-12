export const BRAND = {
  name: 'PORTMYSTUFF',
  partnerName: 'PORTMYSTUFF Partner',
  wallet: 'PORTMYSTUFF Wallet',
  plus: 'PORTMYSTUFF Plus',
  tagline: 'Move anything, anywhere.',
  partnerTagline: 'Deliver. Earn. Grow.',
  defaultUserName: 'PORTMYSTUFF user',
} as const;

export const STORAGE_KEYS = {
  demoDriverSession: 'portmystuff_driver_demo_session',
} as const;

export const CANCEL_REASONS = [
  { code: 'BOOKED_BY_MISTAKE', label: 'Booked by mistake' },
  { code: 'PRICE_TOO_HIGH', label: 'Price too high' },
  { code: 'DRIVER_TOO_LONG', label: 'Driver taking too long' },
  { code: 'FOUND_ALTERNATIVE', label: 'Found another option' },
  { code: 'OTHER', label: 'Other reason' },
] as const;

export type CancelReasonCode = (typeof CANCEL_REASONS)[number]['code'];

export const POSITIVE_RATING_TAGS = ['On time', 'Friendly', 'Careful handling', 'Good communication'];
export const NEGATIVE_RATING_TAGS = ['Unsafe driving', 'Rude', 'Damaged goods', 'Overcharged'];

export const PAYMENT_METHODS = [
  { id: 'wallet', label: BRAND.wallet, description: 'Pay from your wallet balance' },
  { id: 'upi', label: 'UPI / Cash', description: 'Pay driver at pickup' },
  { id: 'card', label: 'Card', description: 'Debit or credit card' },
  { id: 'corporate_bill', label: 'Corporate billing', description: 'Bill to your company account' },
] as const;

export type PaymentMethodId = (typeof PAYMENT_METHODS)[number]['id'];

export const SUPPORT_CATEGORIES = [
  'Trip issue',
  'Payment & wallet',
  'Driver behaviour',
  'Account',
  'Other',
];

export const FAQ_ITEMS = [
  {
    q: 'How do I go online and get jobs?',
    a: 'Complete KYC, training, and vehicle registration. Then toggle Online on the home screen.',
  },
  {
    q: 'How are earnings paid out?',
    a: 'View your balance on the Earnings tab and request instant or standard withdrawal.',
  },
  {
    q: 'What if a customer gives the wrong OTP?',
    a: 'Ask them to check the drop code in their app. Only enter the code they read aloud.',
  },
  {
    q: 'How do penalties work?',
    a: 'Penalties appear under Profile → Penalties. You can dispute any charge with a note.',
  },
  {
    q: 'How do referrals work?',
    a: 'Share your partner referral code. You earn when referred drivers complete trips.',
  },
];
