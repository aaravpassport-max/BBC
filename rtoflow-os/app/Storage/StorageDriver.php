<?php

namespace RTOFLOW\Storage;

if (!defined('ABSPATH')) exit;

/** ENTERPRISE GAP FIX (Phase 2, item 7 — file storage abstraction): the
 * contract both LocalStorageDriver and S3StorageDriver implement, so
 * DocumentService/DocumentAccessController (and any future caller) never
 * need to know which one is active. */
interface StorageDriver
{
    public function put(string $relativePath, string $contents): bool;

    /** Move an already-uploaded temp file (from $_FILES) — avoids reading
     * the whole file into memory twice for the common upload case. */
    public function putFromUploadedFile(string $relativePath, string $tmpName): bool;

    public function get(string $relativePath): ?string;

    public function delete(string $relativePath): bool;

    public function exists(string $relativePath): bool;
}
