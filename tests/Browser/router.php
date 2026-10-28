<?php

require __DIR__.'/environment.php';
$path = parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH);
$file = realpath($root.'/public/'.rawurldecode($path));
if ($file && str_starts_with($file, realpath($root.'/public').DIRECTORY_SEPARATOR)
    && is_file($file) && pathinfo($file, PATHINFO_EXTENSION) !== 'php') return false;
require $root.'/vendor/autoload.php';
$app = require $root.'/bootstrap/app.php';
$app->useStoragePath($runtime.'/storage');
$app->handleRequest(Illuminate\Http\Request::capture());
