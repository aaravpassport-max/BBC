/**
 * Windows 10 helpers: tray icon, toast notifications, Start Menu shortcut (required for toasts).
 */
const path = require('path');
const fs = require('fs');
const { app, nativeImage, shell, dialog } = require('electron');

const APP_ID = 'com.ilrs.app';
const SHORTCUT_NAME = 'ILRS.lnk';

function getAssetsDir() {
  return path.join(__dirname, 'assets');
}

function getTrayIconPath() {
  const candidates = [
    path.join(getAssetsDir(), 'tray-icon.png'),
    path.join(getAssetsDir(), 'icon-32.png'),
    path.join(getAssetsDir(), 'icon.png'),
  ];
  return candidates.find((p) => fs.existsSync(p)) || null;
}

function createTrayIcon() {
  const iconPath = getTrayIconPath();
  if (!iconPath) return nativeImage.createEmpty();

  let image = nativeImage.createFromPath(iconPath);
  if (image.isEmpty()) {
    try {
      image = nativeImage.createFromBuffer(fs.readFileSync(iconPath));
    } catch (_) {
      return nativeImage.createEmpty();
    }
  }

  // Windows tray: use 32px for HiDPI visibility (16px alone is often invisible)
  if (process.platform === 'win32') {
    const size = image.getSize();
    if (size.width > 32 || size.height > 32) {
      image = image.resize({ width: 32, height: 32 });
    }
  }
  return image;
}

function getStartMenuShortcutPath() {
  const programs = path.join(app.getPath('appData'), 'Microsoft', 'Windows', 'Start Menu', 'Programs');
  return path.join(programs, SHORTCUT_NAME);
}

function ensureWindowsToastSupport() {
  if (process.platform !== 'win32') return false;

  app.setAppUserModelId(APP_ID);

  const shortcutPath = getStartMenuShortcutPath();
  try {
    if (!fs.existsSync(shortcutPath)) {
      fs.mkdirSync(path.dirname(shortcutPath), { recursive: true });
      shell.writeShortcutLink(shortcutPath, 'create', {
        target: process.execPath,
        cwd: path.dirname(process.execPath),
        args: process.argv.slice(1).join(' ') || '',
        description: 'ILRS — Intelligent Life Reminder System',
        appUserModelId: APP_ID,
        icon: getTrayIconPath() || process.execPath,
        iconIndex: 0,
      });
      console.log('Created Start Menu shortcut for Windows notifications:', shortcutPath);
    }
    return true;
  } catch (err) {
    console.error('Failed to create Start Menu shortcut:', err.message);
    return false;
  }
}

function showFatalError(title, message) {
  try {
    dialog.showErrorBox(title, message);
  } catch (_) {
    console.error(title, message);
  }
}

module.exports = {
  APP_ID,
  createTrayIcon,
  getTrayIconPath,
  ensureWindowsToastSupport,
  showFatalError,
};
