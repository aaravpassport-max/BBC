<?php

namespace Composer\Autoload;

/**
 * ClassLoader — PSR-4/PSR-0/classmap autoloader.
 * Bundled with RTOFLOW OS (generated equivalent of Composer's ClassLoader).
 */
class ClassLoader
{
    /** @var array<string, list<string>> */
    private array $prefixLengthsPsr4  = [];
    /** @var array<string, list<string>> */
    private array $prefixDirsPsr4     = [];
    /** @var array<string, string> */
    private array $classMap           = [];
    private bool  $registered         = false;

    // ── PSR-4 ────────────────────────────────────────────────────────────────

    public function setPsr4(string $prefix, array $paths): void
    {
        if ($prefix === '') {
            $this->prefixDirsPsr4[''] = $paths;
            return;
        }
        $length = strlen($prefix);
        if ($prefix[$length - 1] !== '\\') {
            throw new \InvalidArgumentException('PSR-4 prefix must end with \\\\: "' . $prefix . '"');
        }
        $firstChar = $prefix[0];
        $this->prefixLengthsPsr4[$firstChar][$prefix] = $length;
        $this->prefixDirsPsr4[$prefix] = $paths;
    }

    public function addPsr4(string $prefix, $paths, bool $prepend = false): void
    {
        $paths = (array) $paths;
        if ($prefix === '') {
            if ($prepend) {
                $this->prefixDirsPsr4[''] = array_merge($paths, $this->prefixDirsPsr4[''] ?? []);
            } else {
                $this->prefixDirsPsr4[''] = array_merge($this->prefixDirsPsr4[''] ?? [], $paths);
            }
            return;
        }
        $length    = strlen($prefix);
        $firstChar = $prefix[0];
        if (!isset($this->prefixLengthsPsr4[$firstChar][$prefix])) {
            $this->prefixLengthsPsr4[$firstChar][$prefix] = $length;
        }
        if ($prepend) {
            $this->prefixDirsPsr4[$prefix] = array_merge($paths, $this->prefixDirsPsr4[$prefix] ?? []);
        } else {
            $this->prefixDirsPsr4[$prefix] = array_merge($this->prefixDirsPsr4[$prefix] ?? [], $paths);
        }
    }

    // ── Classmap ─────────────────────────────────────────────────────────────

    public function addClassMap(array $classMap): void
    {
        $this->classMap = array_merge($this->classMap, $classMap);
    }

    // ── Registration ─────────────────────────────────────────────────────────

    public function register(bool $prepend = false): void
    {
        if ($this->registered) return;
        spl_autoload_register([$this, 'loadClass'], true, $prepend);
        $this->registered = true;
    }

    public function unregister(): void
    {
        if (!$this->registered) return;
        spl_autoload_unregister([$this, 'loadClass']);
        $this->registered = false;
    }

    // ── Load ─────────────────────────────────────────────────────────────────

    public function loadClass(string $class): bool|null
    {
        if ($file = $this->findFile($class)) {
            require $file;
            return true;
        }
        return null;
    }

    public function findFile(string $class): string|false
    {
        // Classmap first (fastest)
        if (isset($this->classMap[$class])) {
            return $this->classMap[$class];
        }

        // Strip leading backslash
        if ($class[0] === '\\') {
            $class = substr($class, 1);
        }

        // PSR-4 lookup
        $logicalPath = strtr($class, '\\', DIRECTORY_SEPARATOR) . '.php';
        $firstChar   = $class[0];

        if (isset($this->prefixLengthsPsr4[$firstChar])) {
            $subPath = $class;
            while (($lastPos = strrpos($subPath, '\\')) !== false) {
                $subPath = substr($subPath, 0, $lastPos + 1);
                $search  = $subPath;
                if (isset($this->prefixDirsPsr4[$search])) {
                    $pathEnd = DIRECTORY_SEPARATOR . substr($logicalPath, strlen($search));
                    foreach ($this->prefixDirsPsr4[$search] as $dir) {
                        if (is_file($file = $dir . $pathEnd)) {
                            return $file;
                        }
                    }
                }
                $subPath = rtrim($subPath, '\\');
            }
        }

        // Fallback PSR-4 (empty prefix)
        if (isset($this->prefixDirsPsr4[''])) {
            foreach ($this->prefixDirsPsr4[''] as $dir) {
                if (is_file($file = $dir . DIRECTORY_SEPARATOR . $logicalPath)) {
                    return $file;
                }
            }
        }

        return false;
    }
}
