<?php

namespace Lilleprinsen\Cargonizer\Infrastructure;

final class Autoloader
{
    public static function register(): void
    {
        spl_autoload_register(function (string $class): void {
            $prefix = 'Lilleprinsen\\Cargonizer\\';
            if (strpos($class, $prefix) !== 0) {
                return;
            }

            $relativeClass = substr($class, strlen($prefix));
            $path = __DIR__ . '/../' . str_replace('\\', '/', $relativeClass) . '.php';

            if (file_exists($path)) {
                require_once $path;
            }
        });
    }
}
