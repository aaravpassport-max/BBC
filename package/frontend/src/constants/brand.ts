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
  displayName: 'portmystuff_display_name',
  onboarded: 'portmystuff_onboarded',
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
    q: 'How do I track my delivery?',
    a: 'Open Trips and tap your active order, or go to the live tracking screen after booking.',
  },
  {
    q: 'Can I cancel my trip?',
    a: 'Yes, before pickup. A cancellation fee may apply depending on trip status.',
  },
  {
    q: `How does ${BRAND.wallet} work?`,
    a: 'Add money to your wallet and pay for trips instantly. Promo credits are applied automatically.',
  },
  {
    q: 'How do referrals work?',
    a: 'Share your code with friends. You earn rewards when they complete their first trip.',
  },
  {
    q: `What is ${BRAND.plus}?`,
    a: 'A monthly membership that waives platform fees on every trip.',
  },
];

export function getDisplayName(): string {
  return (
    localStorage.getItem(STORAGE_KEYS.displayName) ||
    localStorage.getItem('porter_display_name') ||
    BRAND.defaultUserName
  );
}

export function isOnboarded(): boolean {
  return (
    localStorage.getItem(STORAGE_KEYS.onboarded) === 'true' ||
    localStorage.getItem('porter_onboarded') === 'true'
  );
}
