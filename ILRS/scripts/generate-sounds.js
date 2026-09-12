#!/usr/bin/env node
/**
 * Generate loud notification WAV files for ILRS (no external assets required).
 */
const fs = require('fs');
const path = require('path');

const outDir = path.join(__dirname, '..', 'assets', 'sounds');
fs.mkdirSync(outDir, { recursive: true });

const SAMPLE_RATE = 44100;

function writeWav(filePath, samples) {
  const numSamples = samples.length;
  const dataSize = numSamples * 2;
  const buffer = Buffer.alloc(44 + dataSize);
  buffer.write('RIFF', 0);
  buffer.writeUInt32LE(36 + dataSize, 4);
  buffer.write('WAVE', 8);
  buffer.write('fmt ', 12);
  buffer.writeUInt32LE(16, 16);
  buffer.writeUInt16LE(1, 20);
  buffer.writeUInt16LE(1, 22);
  buffer.writeUInt32LE(SAMPLE_RATE, 24);
  buffer.writeUInt32LE(SAMPLE_RATE * 2, 28);
  buffer.writeUInt16LE(2, 32);
  buffer.writeUInt16LE(16, 34);
  buffer.write('data', 36);
  buffer.writeUInt32LE(dataSize, 40);
  for (let i = 0; i < numSamples; i++) {
    const clamped = Math.max(-1, Math.min(1, samples[i]));
    buffer.writeInt16LE(Math.round(clamped * 32767), 44 + i * 2);
  }
  fs.writeFileSync(filePath, buffer);
}

function render(fn, durationSec = 2) {
  const total = Math.floor(SAMPLE_RATE * durationSec);
  const samples = new Float32Array(total);
  for (let i = 0; i < total; i++) samples[i] = fn(i / SAMPLE_RATE, i / total);
  return samples;
}

function mix(...parts) {
  const maxLen = Math.max(...parts.map((p) => p.length));
  const out = new Float32Array(maxLen);
  for (const part of parts) {
    for (let i = 0; i < part.length; i++) out[i] += part[i];
  }
  for (let i = 0; i < out.length; i++) out[i] = Math.max(-1, Math.min(1, out[i]));
  return out;
}

function tone(freq, durationSec, type = 'sine', gain = 0.9) {
  return render((t) => {
    const env = Math.min(1, t * 20) * Math.max(0, 1 - (t - durationSec + 0.05) * 20);
    let v = 0;
    if (type === 'sine') v = Math.sin(2 * Math.PI * freq * t);
    if (type === 'square') v = Math.sign(Math.sin(2 * Math.PI * freq * t));
    if (type === 'saw') v = 2 * ((freq * t) % 1) - 1;
    return v * gain * env;
  }, durationSec);
}

function pulse(freq, onMs, offMs, repeats, gain = 0.95) {
  const cycle = (onMs + offMs) / 1000;
  const duration = cycle * repeats;
  return render((t) => {
    const pos = t % cycle;
    if (pos > onMs / 1000) return 0;
    return Math.sin(2 * Math.PI * freq * t) * gain;
  }, duration);
}

const sounds = {
  'air-horn': () => mix(tone(220, 0.15, 'saw', 1), tone(311, 1.2, 'square', 0.95)),
  'siren': () => render((t) => {
    const f = 600 + 500 * Math.sin(2 * Math.PI * 2 * t);
    return Math.sin(2 * Math.PI * f * t) * 0.95;
  }, 2.5),
  'alarm-clock': () => pulse(880, 120, 80, 14, 1),
  'digital-beep': () => pulse(1200, 90, 70, 10, 0.95),
  'buzzer': () => pulse(180, 80, 40, 16, 1),
  'emergency-alert': () => mix(pulse(740, 200, 100, 6, 0.9), pulse(988, 200, 100, 6, 0.9)),
  'doorbell': () => mix(tone(659, 0.35, 'sine', 1), tone(523, 0.55, 'sine', 0.9)),
  'loud-chime': () => mix(tone(1046, 0.5, 'sine', 1), tone(784, 0.7, 'sine', 0.85), tone(523, 0.9, 'sine', 0.7)),
  'train-whistle': () => render((t) => {
    const f = 420 + 180 * Math.sin(2 * Math.PI * 0.8 * t);
    return Math.sin(2 * Math.PI * f * t) * 0.9;
  }, 2),
  'foghorn': () => tone(110, 2.2, 'saw', 0.95),
  'old-telephone-ring': () => {
    const ring = render((t) => {
      const cycle = t % 3;
      if (cycle > 2) return 0;
      const a = Math.sin(2 * Math.PI * 440 * t);
      const b = Math.sin(2 * Math.PI * 480 * t);
      return (a + b) * 0.55;
    }, 6);
    return ring;
  },
};

for (const [name, fn] of Object.entries(sounds)) {
  writeWav(path.join(outDir, `${name}.wav`), fn());
}

const manifest = Object.keys(sounds).map((id) => ({
  id,
  label: id.split('-').map((w) => w.charAt(0).toUpperCase() + w.slice(1)).join(' '),
}));
manifest.find((s) => s.id === 'old-telephone-ring').label = 'Old Telephone Ring';
fs.writeFileSync(path.join(outDir, 'manifest.json'), JSON.stringify(manifest, null, 2));
console.log(`Generated ${manifest.length} notification sounds in assets/sounds/`);
