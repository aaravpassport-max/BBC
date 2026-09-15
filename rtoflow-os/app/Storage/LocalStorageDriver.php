<?php

namespace RTOFLOW\Storage;

if (!defined('ABSPATH')) exit;

/** ENTERPRISE GAP FIX (Phase 2, item 7 — file storage abstraction): the
 * default driver — wraps wp_upload_dir(), preserving exactly the behaviour
 * every existing caller already had before this abstraction existed. */
class LocalStorageDriver implements StorageDriver
{
    private function fullPath(string $relativePath): string
    {
        return wp_upload_dir()['basedir'] . '/' . ltrim($relativePath, '/');
    }

    public function put(string $relativePath, string $contents): bool
    {
        $path = $this->fullPath($relativePath);
        wp_mkdir_p(dirname($path));
        return file_put_contents($path, $contents) !== false;
    }

    public function putFromUploadedFile(string $relativePath, string $tmpName): bool
    {
        $path = $this->fullPath($relativePath);
        wp_mkdir_p(dirname($path));
        return move_uploaded_file($tmpName, $path);
    }

    public function get(string $relativePath): ?string
    {
        $path = $this->fullPath($relativePath);
        if (!file_exists($path)) return null;
        $contents = file_get_contents($path);
        return $contents === false ? null : $contents;
    }

    public function delete(string $relativePath): bool
    {
        $path = $this->fullPath($relativePath);
        return !file_exists($path) || unlink($path);
    }

    public function exists(string $relativePath): bool
    {
        return file_exists($this->fullPath($relativePath));
    }
}
