<?php

spl_autoload_register(function ($class) {
    if (strpos($class, 'PbbLanding_') !== 0) {
        return;
    }

    $path = __DIR__ . DIRECTORY_SEPARATOR . str_replace('_', DIRECTORY_SEPARATOR, substr($class, strlen('PbbLanding_'))) . '.php';
    if (is_file($path)) {
        require $path;
    }
});
