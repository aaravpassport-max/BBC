#!/usr/bin/env node
/**
 * Generate ILRS "Modern Reminder" icon assets — teal squircle, bell + clock badge.
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
  ihdr[8] = 8;
  ihdr[9] = 6;
  ihdr[10] = 0; ihdr[11] = 0; ihdr[12] = 0;

  const rowSize = 1 + size * 4;
  const raw = Buffer.alloc(rowSize * size);
  for (let y = 0; y < size; y++) {
    const rowStart = y * rowSize;
    raw[rowStart] = 0;
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

/** Modern Reminder icon: teal rounded tile, white bell, amber clock badge */
function modernReminderPixel(x, y, size) {
  const cx = size / 2;
  const cy = size / 2;
  const scale = size * 0.44;
  const nx = (x - cx) / scale;
  const ny = (y - cy) / scale;

  const squircle = Math.pow(Math.abs(nx), 3.2) + Math.pow(Math.abs(ny), 3.2);
  if (squircle > 1.05) return [0, 0, 0, 0];

  const t = (ny + 1) / 2;
  const bg = [
    Math.round(8 + t * 12),
    Math.round(115 + t * 35),
    Math.round(105 + t * 28),
    255,
  ];

  const bx = nx * 1.05;
  const by = ny + 0.08;
  const bell = (bx * bx + by * by * 1.35) < 0.38 && by > -0.38 && by < 0.22;
  const knob = Math.abs(bx) < 0.09 && by > -0.48 && by < -0.32;
  const clapper = (bx * bx + (by - 0.26) * (by - 0.26)) < 0.028;

  const hx = nx - 0.52;
  const hy = ny + 0.5;
  const clockDist = hx * hx + hy * hy;
  const clockFace = clockDist < 0.11;
  const hourHand = Math.abs(hx + 0.01) < 0.035 && hy < 0.02 && hy > -0.06;
  const minuteHand = Math.abs(hx - hy * 0.4) < 0.028 && hx > -0.02 && hx < 0.07;

  if (bell || knob || clapper) return [255, 255, 255, 255];
  if (clockFace) {
    if (hourHand || minuteHand) return [8, 100, 92, 255];
    return [255, 247, 230, 255];
  }

  return bg;
}

for (const size of [16, 32, 256]) {
  const png = createPng(size, modernReminderPixel);
  const name = size === 16 ? 'tray-icon.png' : size === 256 ? 'icon.png' : `icon-${size}.png`;
  fs.writeFileSync(path.join(assetsDir, name), png);
}

fs.copyFileSync(path.join(assetsDir, 'icon.png'), path.join(assetsDir, 'icon-256.png'));
console.log('Generated Modern Reminder icons in assets/');
