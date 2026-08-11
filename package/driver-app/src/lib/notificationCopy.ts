const TEMPLATES: Record<string, () => string> = {
  driver_on_the_way: () => 'You have been assigned to a new trip. Head to pickup.',
  driver_arrived: () => 'You marked arrival at pickup. Await customer OTP.',
  trip_completed: () => 'Trip completed. Earnings credited to your wallet.',
  payout_processed: () => 'Your withdrawal has been processed.',
  incentive_unlocked: () => 'You unlocked a bonus mission reward!',
  penalty_issued: () => 'A penalty was applied to your account. Review in Penalties.',
  new_offer: () => 'You have a new parcel delivery offer nearby.',
  new_ride_offer: () => 'You have a new passenger ride offer nearby.',
  kyc_approved: () => 'Your documents were approved. You can go online.',
  kyc_rejected: () => 'A KYC document needs resubmission.',
};

export function notificationBody(templateId: string, category: string): string {
  const fn = TEMPLATES[templateId];
  if (fn) return fn();
  if (category === 'trip_updates') return 'Update about your active trip.';
  if (category === 'promotions') return 'New incentive or promotion available.';
  if (category === 'account_activity') return 'Account activity on your profile.';
  if (category === 'sos') return 'Safety alert update.';
  return 'You have a new notification.';
}
