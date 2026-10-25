'use strict';
const http = require('http');
const fs = require('fs');
const path = require('path');

/**
 * Static file server on 127.0.0.1 with port 0 (OS-assigned) to avoid EADDRINUSE in parallel tests.
 */
function startStaticServer(rootDir, options) {
  const root = path.resolve(rootDir);
  const defaultPath = (options && options.defaultPath) || '/';
  const server = http.createServer((req, res) => {
    let urlPath = req.url.split('?')[0];
    if (urlPath === '/') urlPath = defaultPath;
    const filePath = path.join(root, urlPath.replace(/^\//, ''));
    if (!filePath.startsWith(root)) {
      res.writeHead(403);
      res.end();
      return;
    }
    fs.readFile(filePath, (err, data) => {
      if (err) {
        res.writeHead(404);
        res.end('not found');
        return;
      }
      const ext = path.extname(filePath);
      const types = { '.html': 'text/html', '.js': 'application/javascript', '.css': 'text/css' };
      res.writeHead(200, { 'Content-Type': types[ext] || 'application/octet-stream' });
      res.end(data);
    });
  });
  return new Promise((resolve, reject) => {
    server.on('error', reject);
    server.listen(0, '127.0.0.1', () => {
      const port = server.address().port;
      resolve({
        port,
        close() {
          return new Promise((r) => server.close(() => r()));
        },
      });
    });
  });
}

function sleep(ms) {
  return new Promise((r) => setTimeout(r, ms));
}

module.exports = { startStaticServer, sleep };
