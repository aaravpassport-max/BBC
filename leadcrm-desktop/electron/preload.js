const { contextBridge } = require('electron');

contextBridge.exposeInMainWorld('leadcrmDesktop', {
  platform: process.platform,
});
