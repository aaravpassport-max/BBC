<?php

namespace RTOFLOW\Storage;

if (!defined('ABSPATH')) exit;

/**
 * ENTERPRISE GAP FIX (Phase 2, item 7 — "File storage is hardcoded to the
 * local filesystem (wp_upload_dir()) everywhere — no S3/object-storage
 * option, which is a real problem for any multi-server or ephemeral-disk
 * hosting setup (uploaded documents would vanish or become inconsistent
 * across app servers)"): a single facade in front of a swappable driver.
 * Selected via the RTOFLOW_STORAGE_DRIVER env var ('local', the default —
 * unchanged behaviour — or 's3'). S3 credentials/bucket/region also come
 * from Env, matching how every other infra-level secret in this codebase
 * (Razorpay keys aside, which are per-tenant admin settings) is configured
 * — see LocalStorageDriver/S3StorageDriver for the two implementations.
 *
 * Rollout scope, stated honestly: wired into DocumentService::upload()/
 * DocumentAccessController::serve() (client document uploads — the
 * highest-volume, most operationally important file path, and the one the
 * gap analysis's own "ephemeral disk" scenario concerns). Backups
 * (BackupService) and any other future upload path can adopt the same
 * FileStorage::put()/get()/delete() calls — the abstraction itself is
 * general-purpose, not document-specific.
 */
class FileStorage
{
    private static ?StorageDriver $driver = null;

    public static function driver(): StorageDriver
    {
        if (self::$driver !== null) return self::$driver;

        $mode = \RTOFLOW\Config\Env::string('RTOFLOW_STORAGE_DRIVER', 'local');
        self::$driver = $mode === 's3' ? new S3StorageDriver() : new LocalStorageDriver();
        return self::$driver;
    }

    /** For tests / explicit override. */
    public static function setDriver(StorageDriver $driver): void
    {
        self::$driver = $driver;
    }

    public static function put(string $relativePath, string $contents): bool
    {
        return self::driver()->put($relativePath, $contents);
    }

    public static function putFromUploadedFile(string $relativePath, string $tmpName): bool
    {
        return self::driver()->putFromUploadedFile($relativePath, $tmpName);
    }

    public static function get(string $relativePath): ?string
    {
        return self::driver()->get($relativePath);
    }

    public static function delete(string $relativePath): bool
    {
        return self::driver()->delete($relativePath);
    }

    public static function exists(string $relativePath): bool
    {
        return self::driver()->exists($relativePath);
    }
}
