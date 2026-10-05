#!/usr/bin/env node
import http from 'node:http';
import fs from 'node:fs';
import path from 'node:path';
import { fileURLToPath } from 'node:url';
import { handleMockApi } from './mock-api.mjs';
import { buildProductionShell, buildImportMapJson } from './production-shell.mjs';
import { placeholderImageSvg } from './placeholder-image.mjs';

const __dirname = path.dirname(fileURLToPath(import.meta.url));
const root = path.resolve(__dirname, '..');
const port = Number(process.env.E2E_PORT || 4173);

const mime = {
  '.html': 'text/html',
  '.js': 'application/javascript',
  '.css': 'text/css',
  '.json': 'application/json',
  '.png': 'image/png',
  '.svg': 'image/svg+xml',
};

const server = http.createServer((req, res) => {
  const url = new URL(req.url || '/', `http://127.0.0.1:${port}`);
  if (url.pathname === '/' && url.searchParams.has('s2nri_import_map')) {
    res.writeHead(200, {
      'Content-Type': 'application/json; charset=utf-8',
      'Cache-Control': 'no-store',
    });
    res.end(buildImportMapJson(root));
    return;
  }

  if (url.pathname === '/' && url.searchParams.has('s2nri_img')) {
    const key = url.searchParams.get('s2nri_img') || 'img';
    res.writeHead(200, { 'Content-Type': 'image/svg+xml', 'Cache-Control': 'public, max-age=3600' });
    res.end(placeholderImageSvg(key));
    return;
  }

  if (url.pathname.startsWith('/mock-api')) {
    const respond = (payload) => {
      res.writeHead(200, { 'Content-Type': 'application/json' });
      res.end(JSON.stringify(payload));
    };
    const run = async () => {
      let requestBody = null;
      if (req.method === 'PUT' || req.method === 'POST') {
        requestBody = await new Promise((resolve, reject) => {
          const chunks = [];
          req.on('data', (chunk) => chunks.push(chunk));
          req.on('end', () => {
            const raw = Buffer.concat(chunks).toString('utf8');
            if (!raw.trim()) {
              resolve({});
              return;
            }
            try {
              resolve(JSON.parse(raw));
            } catch {
              resolve({});
            }
          });
          req.on('error', reject);
        });
      }
      respond(handleMockApi(url.pathname + url.search, req.method || 'GET', requestBody));
    };
    run().catch((err) => {
      res.writeHead(500, { 'Content-Type': 'application/json' });
      res.end(JSON.stringify({ error: String(err?.message || err) }));
    });
    return;
  }

  if (
    !url.pathname.startsWith('/assets/') &&
    url.searchParams.get('shell') === 'production'
  ) {
    res.writeHead(200, { 'Content-Type': 'text/html; charset=utf-8' });
    res.end(buildProductionShell(root));
    return;
  }

  if (url.pathname === '/s2nri-admin' || url.pathname.startsWith('/s2nri-admin/')) {
    res.writeHead(200, { 'Content-Type': 'text/html; charset=utf-8' });
    res.end(buildProductionShell(root, { basePath: '/s2nri-admin' }));
    return;
  }

  if (url.pathname === '/portal' || url.pathname.startsWith('/portal/')) {
    res.writeHead(200, { 'Content-Type': 'text/html; charset=utf-8' });
    res.end(buildProductionShell(root, { basePath: '/portal' }));
    return;
  }

  if (url.pathname === '/s2nri-builder' || url.pathname.startsWith('/s2nri-builder/')) {
    res.writeHead(200, { 'Content-Type': 'text/html; charset=utf-8' });
    fs.readFile(path.join(root, 'e2e/builder-shell.html'), (err, data) => {
      if (err) {
        res.writeHead(500);
        res.end('Builder shell missing');
        return;
      }
      res.end(data);
    });
    return;
  }

  if (url.pathname.startsWith('/e2e/')) {
    const e2ePath = path.join(root, url.pathname.slice(1).split('?')[0]);
    fs.readFile(e2ePath, (err, data) => {
      if (err) {
        res.writeHead(404);
        res.end('Not found');
        return;
      }
      const ext = path.extname(e2ePath);
      res.writeHead(200, { 'Content-Type': mime[ext] || 'application/octet-stream' });
      res.end(data);
    });
    return;
  }

  let filePath;
  if (url.pathname.startsWith('/builder/')) {
    filePath = path.join(root, url.pathname.slice(1).split('?')[0]);
  } else if (url.pathname.startsWith('/assets/release/')) {
    const assetPath = url.pathname.slice(1).split('?')[0];
    filePath = path.join(root, assetPath);
  } else if (url.pathname.startsWith('/assets/')) {
    const assetPath = url.pathname.slice(1).split('?')[0];
    filePath = path.join(root, assetPath);
  } else {
    filePath = path.join(root, 'e2e/spa.html');
  }

  if (!filePath.startsWith(root)) {
    res.writeHead(403);
    res.end('Forbidden');
    return;
  }

  fs.readFile(filePath, (err, data) => {
    if (err) {
      res.writeHead(404);
      res.end('Not found');
      return;
    }
    const ext = path.extname(filePath);
    res.writeHead(200, { 'Content-Type': mime[ext] || 'application/octet-stream' });
    res.end(data);
  });
});

server.listen(port, '127.0.0.1', () => {
  console.log(`E2E static server http://127.0.0.1:${port}`);
});
