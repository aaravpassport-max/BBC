/**
 * Preload — minimal context bridge.
 * Only exposes what the React app actually needs.
 */
const { contextBridge, ipcRenderer } = require('electron');

contextBridge.exposeInMainWorld('electronAPI', {
  // Allow React to know it's running inside Electron desktop
  isDesktop: true,
  platform:  process.platform,
});
