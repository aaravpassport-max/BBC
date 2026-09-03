const { contextBridge, ipcRenderer } = require('electron');

contextBridge.exposeInMainWorld('ilrs', {
  // Database
  query: (sql, params) => ipcRenderer.invoke('db-query', { sql, params }),

  // Notifications
  sendNotification: (data) => ipcRenderer.send('send-notification', data),
  testNotification: () => ipcRenderer.invoke('test-notification'),

  // Export
  exportData: (data) => ipcRenderer.invoke('export-data', data),

  // App paths
  getAppPath: () => ipcRenderer.invoke('get-app-path'),

  // Window
  minimizeToTray: () => ipcRenderer.send('minimize-to-tray'),
  showWindow: () => ipcRenderer.send('show-window'),
  openBackupFolder: (path) => ipcRenderer.send('open-backup-folder', path),

  // Listeners
  onNavigate: (callback) => ipcRenderer.on('navigate', (_, page) => callback(page)),
  onReminderDue: (callback) => ipcRenderer.on('reminder-due', (_, reminder) => callback(reminder)),
  onPauseAlerts: (callback) => ipcRenderer.on('pause-alerts', (_, minutes) => callback(minutes)),
  onPlaySound: (callback) => ipcRenderer.on('play-alert-sound', (_, soundId) => callback(soundId)),

  // Remove listeners
  removeAllListeners: (channel) => ipcRenderer.removeAllListeners(channel)
});
