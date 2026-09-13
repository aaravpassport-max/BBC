const { contextBridge, ipcRenderer } = require('electron');

contextBridge.exposeInMainWorld('ilrs', {
  // Database
  query: (sql, params) => ipcRenderer.invoke('db-query', { sql, params }),

  // Notifications
  sendNotification: (data) => ipcRenderer.send('send-notification', data),
  testNotification: () => ipcRenderer.invoke('test-notification'),
  scheduleTestAlarm: () => ipcRenderer.invoke('schedule-test-alarm'),
  getSystemClock: () => ipcRenderer.invoke('get-system-clock'),
  computeNextFire: (startDate, time, repeatType, repeatValue) =>
    ipcRenderer.invoke('compute-next-fire', { startDate, time, repeatType, repeatValue }),
  completeReminder: (id) => ipcRenderer.invoke('complete-reminder', { id }),
  snoozeReminder: (id, minutes) => ipcRenderer.invoke('snooze-reminder', { id, minutes }),
  postponeReminder: (id, dateStr, timeStr) =>
    ipcRenderer.invoke('postpone-reminder', { id, dateStr, timeStr }),
  deleteReminder: (id) => ipcRenderer.invoke('delete-reminder', { id }),
  bulkDeleteReminders: (ids) => ipcRenderer.invoke('bulk-delete-reminders', { ids }),
  updateWorkflowStatus: (id, workflowStatus) =>
    ipcRenderer.invoke('update-workflow-status', { id, workflowStatus }),
  completeModuleAction: (type, id, doseTime) =>
    ipcRenderer.invoke('complete-module-action', { type, id, doseTime }),
  applyAutoStart: (enable) => ipcRenderer.invoke('apply-auto-start', enable),

  // Export
  exportData: (data) => ipcRenderer.invoke('export-data', data),

  // App paths
  getAppPath: () => ipcRenderer.invoke('get-app-path'),

  // Window
  minimizeToTray: () => ipcRenderer.send('minimize-to-tray'),
  showWindow: () => ipcRenderer.send('show-window'),
  openBackupFolder: (path) => ipcRenderer.send('open-backup-folder', path),
  performBackup: (force = false) => ipcRenderer.invoke('perform-backup', { force }),
  createInquiry: (data) => ipcRenderer.invoke('create-inquiry', data),
  updateInquiry: (id, data) => ipcRenderer.invoke('update-inquiry', { id, data }),
  changeInquiryStage: (id, stageKey, options) => ipcRenderer.invoke('change-inquiry-stage', { id, stageKey, options }),
  logInquiryActivity: (id, type, title, body) => ipcRenderer.invoke('log-inquiry-activity', { id, type, title, body }),
  findInquiryDuplicates: (data) => ipcRenderer.invoke('find-inquiry-duplicates', data),
  reopenInquiry: (id, stageKey) => ipcRenderer.invoke('reopen-inquiry', { id, stageKey }),
  deleteInquiry: (id) => ipcRenderer.invoke('delete-inquiry', { id }),
  bulkDeleteInquiries: (ids) => ipcRenderer.invoke('bulk-delete-inquiries', { ids }),
  getPipelineStages: () => ipcRenderer.invoke('get-pipeline-stages'),
  savePipelineStage: (stage) => ipcRenderer.invoke('save-pipeline-stage', { stage }),
  getInquiryTemplates: () => ipcRenderer.invoke('get-inquiry-templates'),
  createInquiryTemplate: (data) => ipcRenderer.invoke('create-inquiry-template', data),
  deleteInquiryTemplate: (id) => ipcRenderer.invoke('delete-inquiry-template', { id }),
  getWorkAnalytics: () => ipcRenderer.invoke('get-work-analytics'),
  refreshInquiryHealth: () => ipcRenderer.invoke('refresh-inquiry-health'),

  // Listeners
  onNavigate: (callback) => ipcRenderer.on('navigate', (_, page) => callback(page)),
  onReminderDue: (callback) => ipcRenderer.on('reminder-due', (_, reminder) => callback(reminder)),
  onPauseAlerts: (callback) => ipcRenderer.on('pause-alerts', (_, minutes) => callback(minutes)),
  onPlaySound: (callback) => ipcRenderer.on('play-alert-sound', (_, soundId) => callback(soundId)),
  onSpeakReminder: (callback) => ipcRenderer.on('speak-reminder', (_, payload) => callback(payload)),
  onNotificationClicked: (callback) => ipcRenderer.on('notification-clicked', (_, reminder) => callback(reminder)),
  onReminderUpdated: (callback) => ipcRenderer.on('reminder-updated', () => callback()),

  // Remove listeners
  removeAllListeners: (channel) => ipcRenderer.removeAllListeners(channel)
});
