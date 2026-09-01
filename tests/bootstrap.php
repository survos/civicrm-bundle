<?php

declare(strict_types=1);

$packageRoot = dirname(__DIR__);
$monoRoot = dirname($packageRoot, 2);
$autoload = $packageRoot.'/vendor/autoload.php';
if (!is_file($autoload)) {
    $autoload = $monoRoot.'/vendor/autoload.php';
}
require $autoload;

spl_autoload_register(static function (string $class) use ($packageRoot, $monoRoot): void {
    $prefixes = [
        'Survos\\CivicrmBundle\\Tests\\' => $packageRoot.'/tests/',
        'Survos\\CivicrmBundle\\' => $packageRoot.'/src/',
        // The client lives in the framework-agnostic sibling package.
        'Survos\\Civicrm\\' => $monoRoot.'/lib/civicrm-php/src/',
        'Survos\\Kit\\' => $monoRoot.'/bu/kit-bundle/src/',
    ];
    foreach ($prefixes as $prefix => $directory) {
        if (!str_starts_with($class, $prefix)) {
            continue;
        }
        $file = $directory.str_replace('\\', '/', substr($class, strlen($prefix))).'.php';
        if (is_file($file)) {
            require $file;
        }
    }
});
