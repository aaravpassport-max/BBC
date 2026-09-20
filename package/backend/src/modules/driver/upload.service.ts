import { randomUUID } from 'crypto';
import fs from 'fs';
import path from 'path';
import { Errors } from '../../utils/errors';

const UPLOAD_DIR = process.env.UPLOAD_DIR || path.join(process.cwd(), 'uploads', 'proof-photos');
const MAX_BYTES = 5 * 1024 * 1024;

function ensureUploadDir() {
  fs.mkdirSync(UPLOAD_DIR, { recursive: true });
}

/**
 * Accepts a base64 data-URL image and stores it on disk.
 * Returns a public URL path served by express.static.
 */
export function saveProofPhoto(imageBase64: string): { url: string } {
  const match = /^data:(image\/(?:jpeg|jpg|png|webp));base64,(.+)$/i.exec(imageBase64);
  if (!match) {
    throw Errors.validation({ image_base64: 'Expected a base64 data URL (image/jpeg or image/png).' });
  }

  const mime = match[1].toLowerCase();
  const ext = mime.includes('png') ? 'png' : mime.includes('webp') ? 'webp' : 'jpg';
  const buffer = Buffer.from(match[2], 'base64');

  if (buffer.length === 0 || buffer.length > MAX_BYTES) {
    throw Errors.validation({ image_base64: `Image must be between 1 byte and ${MAX_BYTES} bytes.` });
  }

  ensureUploadDir();
  const filename = `${randomUUID()}.${ext}`;
  const filePath = path.join(UPLOAD_DIR, filename);
  fs.writeFileSync(filePath, buffer);

  return { url: `/uploads/proof-photos/${filename}` };
}

function saveImageUpload(imageBase64: string, subdir: string, publicPath: string): { url: string } {
  const match = /^data:(image\/(?:jpeg|jpg|png|webp));base64,(.+)$/i.exec(imageBase64);
  if (!match) {
    throw Errors.validation({ image_base64: 'Expected a base64 data URL (image/jpeg or image/png).' });
  }

  const mime = match[1].toLowerCase();
  const ext = mime.includes('png') ? 'png' : mime.includes('webp') ? 'webp' : 'jpg';
  const buffer = Buffer.from(match[2], 'base64');

  if (buffer.length === 0 || buffer.length > MAX_BYTES) {
    throw Errors.validation({ image_base64: `Image must be between 1 byte and ${MAX_BYTES} bytes.` });
  }

  const dir = path.join(process.cwd(), 'uploads', subdir);
  fs.mkdirSync(dir, { recursive: true });
  const filename = `${randomUUID()}.${ext}`;
  fs.writeFileSync(path.join(dir, filename), buffer);

  return { url: `${publicPath}/${filename}` };
}

export function saveKycDocument(imageBase64: string): { url: string } {
  return saveImageUpload(imageBase64, 'kyc', '/uploads/kyc');
}
