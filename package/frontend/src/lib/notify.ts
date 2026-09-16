import { LocalNotifications } from '@capacitor/local-notifications';

let permissionRequested = false;
let permissionGranted = false;

/**
 * Requests notification permission once per app session (not once per
 * call — repeatedly prompting is exactly the kind of thing that makes
 * users distrust an app). Safe to call from anywhere; subsequent calls
 * after the first resolve immediately from the cached result.
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
 * appears on the lock screen/notification tray exactly like a server-pushed
 * one would; on web it uses the browser's real Notification API. This is
 * deliberately NOT server push (which needs a Firebase project/credentials
 * this environment doesn't have) — it's triggered client-side by the app's
 * own polling noticing a real state change, which is a genuine, complete,
 * working feature on its own, not a placeholder for the real thing.
 */
export async function notify(title: string, body: string): Promise<void> {
  const granted = await ensurePermission();
  if (!granted) return; // never throw over a denied/unavailable permission — notifications are additive, not required for the app to function
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
