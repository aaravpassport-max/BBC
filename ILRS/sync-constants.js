/** Shared constants for ILRS file-based multi-computer sync */

const SYNC_EVENT_SCHEMA_VERSION = 1;
const SYNC_MANIFEST_VERSION = 1;

const OUTBOX_STATUS = {
  PENDING: 'pending',
  PUBLISHING: 'publishing',
  PUBLISHED: 'published',
  FAILED: 'failed',
};

const SYNC_ROOT_SUBDIRS = ['Sync/Devices', 'Sync/Changes', 'Sync/Conflicts', 'Sync/Locks', 'Sync/Snapshots', 'Attachments', 'Backups'];

module.exports = {
  SYNC_EVENT_SCHEMA_VERSION,
  SYNC_MANIFEST_VERSION,
  OUTBOX_STATUS,
  SYNC_ROOT_SUBDIRS,
};
