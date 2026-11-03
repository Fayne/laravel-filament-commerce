<?php

declare(strict_types=1);

$packageRoot = dirname(__DIR__);
$autoload = $packageRoot.'/vendor/autoload.php';
if (! is_file($autoload)) {
    $autoload = dirname($packageRoot, 2).'/vendor/autoload.php';
}
if (! is_file($autoload)) {
    throw new RuntimeException('Install the package dependencies with Composer before running tests.');
}

$loader = require $autoload;
$loader->addPsr4('Fayne\\Commerce\\', $packageRoot.'/src', true);
$loader->addPsr4('Fayne\\Commerce\\Tests\\', $packageRoot.'/tests', true);
$shoppingCartRoot = dirname($packageRoot).'/laravel-shopping-cart';
if (is_dir($shoppingCartRoot.'/src')) {
    $loader->addPsr4('Fayne\\ShoppingCart\\', $shoppingCartRoot.'/src', true);
}
$promotionRoot = dirname($packageRoot).'/laravel-filament-promotion';
if (is_dir($promotionRoot.'/src')) {
    $loader->addPsr4('Fayne\\Promotion\\', $promotionRoot.'/src', true);
}
