/**
 * Inquiry (and entity) file attachments — local store + sync folder copy.
 */
const fs = require('fs');
const path = require('path');
const { randomUUID } = require('crypto');
const { getSetting } = require('./sync-device');
const { maybeSyncEntity } = require('./sync-hook');
const { ensureAttachmentOnDrive, sha256File } = require('./sync-attachments');

function getLocalAttachmentsRoot() {
  if (process.env.ILRS_TEST_ATTACHMENTS_DIR) {
    return process.env.ILRS_TEST_ATTACHMENTS_DIR;
  }
  const { app } = require('electron');
  return path.join(app.getPath('userData'), 'attachments');
}

function localPathForAttachment(id, fileName) {
  const safe = String(fileName || 'file').replace(/[<>:"/\\|?*]/g, '_');
  return path.join(getLocalAttachmentsRoot(), id, safe);
}

function registerAttachmentFromFile(db, {
  inquiryId,
  entityType = 'inquiry',
  entityId,
  sourcePath,
  fileName,
  mimeType = '',
}) {
  if (!db || !sourcePath || !fs.existsSync(sourcePath)) {
    return { success: false, error: 'source_missing' };
  }
  const id = randomUUID();
  const name = fileName || path.basename(sourcePath);
  const sha256 = sha256File(sourcePath);
  const sizeBytes = fs.statSync(sourcePath).size;
  const targetEntityId = entityId || inquiryId;
  const localPath = localPathForAttachment(id, name);
  fs.mkdirSync(path.dirname(localPath), { recursive: true });
  fs.copyFileSync(sourcePath, localPath);

  const storageRel = path.join(id, name);
  db.prepare(`
    INSERT INTO attachments (
      id, inquiry_id, entity_type, entity_id, file_name, mime_type, size_bytes,
      sha256, storage_rel_path, local_rel_path, created_at, sync_revision
    ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, datetime('now'), 1)
  `).run(
    id,
    inquiryId || targetEntityId || '',
    entityType,
    targetEntityId || '',
    name,
    mimeType || '',
    sizeBytes,
    sha256,
    storageRel,
    storageRel,
  );

  const folder = getSetting(db, 'sync_folder_path', '');
  if (folder) {
    const row = db.prepare('SELECT * FROM attachments WHERE id = ?').get(id);
    ensureAttachmentOnDrive(folder, localPath, row);
  }

  maybeSyncEntity(db, 'attachment', id, 'upsert');
  return { success: true, id, path: localPath };
}

function deleteAttachment(db, attachmentId) {
  const row = db.prepare('SELECT * FROM attachments WHERE id = ?').get(attachmentId);
  if (!row) return { success: false, error: 'not_found' };
  db.prepare(`
    UPDATE attachments SET deleted_at = datetime('now'), sync_revision = COALESCE(sync_revision, 0) + 1
    WHERE id = ?
  `).run(attachmentId);
  maybeSyncEntity(db, 'attachment', attachmentId, 'delete');
  return { success: true };
}

function listAttachmentsForEntity(db, entityType, entityId) {
  return db.prepare(`
    SELECT * FROM attachments
    WHERE entity_type = ? AND entity_id = ? AND deleted_at IS NULL
    ORDER BY created_at DESC
  `).all(entityType, entityId);
}

function resolveLocalPath(db, attachmentId) {
  const row = db.prepare('SELECT * FROM attachments WHERE id = ?').get(attachmentId);
  if (!row) return null;
  if (row.local_rel_path) {
    return path.join(getLocalAttachmentsRoot(), row.local_rel_path);
  }
  return localPathForAttachment(row.id, row.file_name);
}

module.exports = {
  getLocalAttachmentsRoot,
  registerAttachmentFromFile,
  deleteAttachment,
  listAttachmentsForEntity,
  resolveLocalPath,
};
