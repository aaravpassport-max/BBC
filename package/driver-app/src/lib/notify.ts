import { LocalNotifications } from '@capacitor/local-notifications';

let permissionRequested = false;
let permissionGranted = false;

/**
 * Requests notification permission once per app session. Safe to call from
 * anywhere; subsequent calls resolve immediately from the cached result.
 */
async function ensurePermission(): Promise<boolean> {
  if (permissionRequested) return permissionGranted;
  permissionRequested = true;
  try {
    const result = await LocalNotifications.requestPermissions();
    permissionGranted = result.display === 'granted';
  } catch {
    permissionGranted = false;
  }
  return permissionGranted;
}

/**
 * Fires a real, OS-level local notification — on native Android this
 * appears on the lock screen/notification tray; on web it uses the
 * browser's real Notification API. Deliberately client-side (triggered by
 * this app's own polling), not server push — see the note in the customer
 * app's identical helper for why (no Firebase project/credentials in this
 * environment). A driver has a short window to respond to a job offer, so
 * this matters more here than almost anywhere else in the product.
 */
export async function notify(title: string, body: string): Promise<void> {
  const granted = await ensurePermission();
  if (!granted) return;
  try {
    await LocalNotifications.schedule({
      notifications: [
        {
          id: Math.floor(Math.random() * 2147483647),
          title,
          body,
        },
      ],
    });
  } catch (err) {
    console.error('Failed to schedule local notification:', err);
  }
}
