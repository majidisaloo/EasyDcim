<?php

declare(strict_types=1);

spl_autoload_register(static function (string $class): void {
    $prefix = 'EasyDcimBw\\';
    if (strpos($class, $prefix) !== 0) {
        return;
    }

    $relative = substr($class, strlen($prefix));
    $path = __DIR__ . '/' . str_replace('\\', '/', $relative) . '.php';
    if (file_exists($path)) {
        require_once $path;
    }
});
