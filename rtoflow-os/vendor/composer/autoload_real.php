<?php

class ComposerAutoloaderInitRTOFLOW
{
    private static ?\Composer\Autoload\ClassLoader $loader = null;

    public static function loadClassLoader(string $class): void
    {
        if ($class === 'Composer\Autoload\ClassLoader') {
            require __DIR__ . '/ClassLoader.php';
        }
    }

    public static function getLoader(): \Composer\Autoload\ClassLoader
    {
        if (self::$loader !== null) {
            return self::$loader;
        }

        spl_autoload_register(['ComposerAutoloaderInitRTOFLOW', 'loadClassLoader'], true, true);
        self::$loader = $loader = new \Composer\Autoload\ClassLoader();
        spl_autoload_unregister(['ComposerAutoloaderInitRTOFLOW', 'loadClassLoader']);

        // PSR-4: RTOFLOW\ → app/
        $root = dirname(__DIR__, 2); // vendor/composer/ → plugin root
        $loader->setPsr4('RTOFLOW\\', [$root . '/app']);

        // FPDF (global class, no namespace) — loaded via classmap
        $loader->addClassMap([
            'FPDF' => $root . '/vendor/setasign/fpdf/fpdf.php',
        ]);

        $loader->register(true);

        // Autoload files declared in composer.json "files" key
        require_once $root . '/app/Support/helpers.php';

        return $loader;
    }
}

