#!/usr/bin/env node
/**
 * Generate minimal ILRS icon assets (PNG) without external dependencies.
 */
const fs = require('fs');
const path = require('path');
const zlib = require('zlib');

const assetsDir = path.join(__dirname, '..', 'assets');
fs.mkdirSync(assetsDir, { recursive: true });

function crc32(buf) {
  let c = 0xffffffff;
  for (let i = 0; i < buf.length; i++) {
    c ^= buf[i];
    for (let k = 0; k < 8; k++) c = (c >>> 1) ^ (0xedb88320 & -(c & 1));
  }
  return (c ^ 0xffffffff) >>> 0;
}

function chunk(type, data) {
  const len = Buffer.alloc(4);
  len.writeUInt32BE(data.length);
  const typeBuf = Buffer.from(type);
  const crc = Buffer.alloc(4);
  crc.writeUInt32BE(crc32(Buffer.concat([typeBuf, data])));
  return Buffer.concat([len, typeBuf, data, crc]);
}

function createPng(size, drawPixel) {
  const signature = Buffer.from([137, 80, 78, 71, 13, 10, 26, 10]);
  const ihdr = Buffer.alloc(13);
  ihdr.writeUInt32BE(size, 0);
  ihdr.writeUInt32BE(size, 4);
  ihdr[8] = 8; // bit depth
  ihdr[9] = 6; // RGBA
  ihdr[10] = 0; ihdr[11] = 0; ihdr[12] = 0;

  const rowSize = 1 + size * 4;
  const raw = Buffer.alloc(rowSize * size);
  for (let y = 0; y < size; y++) {
    const rowStart = y * rowSize;
    raw[rowStart] = 0; // filter none
    for (let x = 0; x < size; x++) {
      const [r, g, b, a] = drawPixel(x, y, size);
      const px = rowStart + 1 + x * 4;
      raw[px] = r; raw[px + 1] = g; raw[px + 2] = b; raw[px + 3] = a;
    }
  }

  const compressed = zlib.deflateSync(raw);
  return Buffer.concat([
    signature,
    chunk('IHDR', ihdr),
    chunk('IDAT', compressed),
    chunk('IEND', Buffer.alloc(0)),
  ]);
}

function bellPixel(x, y, size) {
  const cx = size / 2;
  const cy = size / 2;
  const dx = (x - cx) / size;
  const dy = (y - cy) / size;
  const dist = Math.sqrt(dx * dx + dy * dy);
  const bell = dist < 0.32 && dy > -0.15 && dy < 0.2;
  const clapper = dist < 0.08 && dy > 0.18;
  const bg = dist < 0.46;
  if (bell || clapper) return [99, 102, 241, 255];
  if (bg) return [15, 15, 26, 255];
  return [0, 0, 0, 0];
}

for (const size of [16, 32, 256]) {
  const png = createPng(size, bellPixel);
  const name = size === 16 ? 'tray-icon.png' : size === 256 ? 'icon.png' : `icon-${size}.png`;
  fs.writeFileSync(path.join(assetsDir, name), png);
}

// Copy for electron-builder targets
fs.copyFileSync(path.join(assetsDir, 'icon.png'), path.join(assetsDir, 'icon-256.png'));
console.log('Generated ILRS icons in assets/');
