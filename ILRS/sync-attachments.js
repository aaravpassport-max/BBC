/**
 * Attachment files in ILRS/Attachments (metadata synced as attachment entity).
 */
const fs = require('fs');
const path = require('path');
const { createHash } = require('crypto');
const { pathsForRoot } = require('./sync-folder');

function sha256File(filePath) {
  const buf = fs.readFileSync(filePath);
  return createHash('sha256').update(buf).digest('hex');
}

function driveFilePath(syncFolderRoot, attachmentRow) {
  const p = pathsForRoot(syncFolderRoot);
  const rel = attachmentRow.storage_rel_path || path.join(attachmentRow.id, attachmentRow.file_name);
  return path.join(p.attachments, rel);
}

function ensureAttachmentOnDrive(syncFolderRoot, localPath, attachmentRow) {
  if (!syncFolderRoot || !localPath || !attachmentRow?.id) {
    return { success: false, error: 'missing_args' };
  }
  if (!fs.existsSync(localPath)) {
    return { success: false, error: 'local_file_missing' };
  }
  const dest = driveFilePath(syncFolderRoot, attachmentRow);
  fs.mkdirSync(path.dirname(dest), { recursive: true });
  if (!fs.existsSync(dest)) {
    fs.copyFileSync(localPath, dest);
  } else {
    const existingHash = sha256File(dest);
    const localHash = attachmentRow.sha256 || sha256File(localPath);
    if (existingHash !== localHash) {
      fs.copyFileSync(localPath, dest);
    }
  }
  return { success: true, path: dest };
}

function ensureLocalAttachmentFromDrive(syncFolderRoot, attachmentRow, localDestPath) {
  const src = driveFilePath(syncFolderRoot, attachmentRow);
  if (!fs.existsSync(src)) {
    return { success: false, error: 'drive_file_missing' };
  }
  fs.mkdirSync(path.dirname(localDestPath), { recursive: true });
  if (!fs.existsSync(localDestPath)) {
    fs.copyFileSync(src, localDestPath);
  }
  return { success: true, path: localDestPath };
}

module.exports = {
  sha256File,
  driveFilePath,
  ensureAttachmentOnDrive,
  ensureLocalAttachmentFromDrive,
};
