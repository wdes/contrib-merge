<?php

declare(strict_types = 1);

/**
 * Loads the tool and the Tests\ namespace with or without Composer, so the
 * suite also runs on a system-wide phpunit.
 */

$autoload = __DIR__ . '/../vendor/autoload.php';
if (is_file($autoload)) {
    require $autoload;
} else {
    require dirname(__DIR__) . '/contrib-merge';
    spl_autoload_register(static function (string $class): void {
        if (! str_starts_with($class, 'Tests\\')) {
            return;
        }
        $relative = str_replace('\\', '/', substr($class, strlen('Tests\\')));
        $file = __DIR__ . '/' . $relative . '.php';
        if (is_file($file)) {
            require $file;
        }
    });
}
