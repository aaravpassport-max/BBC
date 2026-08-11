const TEMPLATES: Record<string, (ctx?: Record<string, string>) => string> = {
  booking_confirmed: () => 'Your booking is confirmed. We are finding a driver nearby.',
  driver_assigned: () => 'A driver has been assigned and is heading to your pickup location.',
  driver_on_the_way: () => 'Your driver is on the way to the pickup point.',
  driver_arrived: () => 'Your driver has arrived at the pickup location.',
  pickup_verified: (ctx) =>
    ctx?.booking_type === 'ride'
      ? 'Pickup verified. Your ride is now in progress.'
      : 'Pickup verified. Your goods are now on the way.',
  trip_completed: (ctx) =>
    ctx?.booking_type === 'ride'
      ? 'Ride complete. Rate your experience in the app.'
      : 'Delivery complete. Rate your experience in the app.',
  trip_cancelled: () => 'Your trip was cancelled.',
  wallet_credited: () => 'Money has been added to your wallet.',
  referral_reward: () => 'You earned a referral reward!',
  promo_offer: () => 'A new offer is available for your next trip.',
  subscription_active: () => 'Your membership is now active.',
  sos_acknowledged: () => 'Our safety team has acknowledged your SOS alert.',
  no_drivers_found: () => 'We could not find a driver right now. Please try again shortly.',
};

export function notificationBody(templateId: string, category: string): string {
  const fn = TEMPLATES[templateId];
  if (fn) return fn();
  if (category === 'trip_updates') return 'Update about your active trip.';
  if (category === 'promotions') return 'New promotion available.';
  if (category === 'account_activity') return 'Account activity on your profile.';
  if (category === 'sos') return 'Safety alert update.';
  return 'You have a new notification.';
}
